<?php

namespace App\Delivery;

use App\Atlassian\AtlassianException;
use App\Confluence\ConfluenceClient;
use App\Entity\Project;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Repository\DeveloperRepository;
use App\Service\ProjectScope;
use App\Service\SprintService;

/**
 * Corrélation entre les versions de mes projets et les livraisons des web services :
 * - tickets de mes projets liés à des tickets des projets WS ;
 * - dates de livraison WS lues dans le calendrier Confluence (référence) et comparées à Jira ;
 * - tickets WS sans lien vers un ticket de mes projets.
 */
class DeliveryService
{
    private const LINK_FIELDS = ['issuelinks'];

    private ?DeliveryScope $scope = null;

    public function __construct(
        private readonly DeliverySettings $settings,
        private readonly JiraClient $jira,
        private readonly ConfluenceClient $confluence,
        private readonly SprintService $sprints,
        private readonly DeveloperRepository $developers,
        private readonly DeliveryTableParser $parser,
        private readonly DeliveryScopeResolver $scopeResolver,
    ) {
    }

    public function getCalendar(): DeliveryCalendar
    {
        $pageId = $this->settings->getPageId();
        if (!$pageId) {
            return new DeliveryCalendar([], error: 'Aucune page Confluence paramétrée.');
        }
        try {
            $content = $this->confluence->getPageContent($pageId);
        } catch (AtlassianException $e) {
            return new DeliveryCalendar([], error: $e->getMessage());
        }
        $parsed = $this->parser->parse($content['storage'], $this->settings->getDateColumn(), $this->settings->getVersionColumn());

        return new DeliveryCalendar($parsed['rows'], $content['info']->title, $content['info']->url, $parsed['error'], $parsed['headers']);
    }

    /**
     * @param Project[] $projects mes projets (périmètre des tickets contrôlés)
     *
     * @return array{
     *     calendar: DeliveryCalendar,
     *     checks: DeliveryCheck[],
     *     versions: array<int, array{projectKey: string, name: string, released: bool, jiraDate: ?\DateTimeImmutable, confluenceDate: ?\DateTimeImmutable, rows: DeliveryRow[], level: string, message: ?string}>,
     *     unlinked: Issue[],
     *     myVersionsWithoutDate: string[]
     * }
     */
    public function getReport(array $projects, ?DeliveryCalendar $calendar = null, ?DeliveryScope $deliveryScope = null): array
    {
        $calendar ??= $this->getCalendar();
        $deliveryScope ??= $this->scopeResolver->resolve($projects);
        $this->scope = $deliveryScope;
        $wsKeys = $deliveryScope->wsKeys;
        $scope = $deliveryScope->myJql;
        $myKeys = $deliveryScope->myKeys;
        if ($deliveryScope->isEmpty() || [] === $wsKeys) {
            return ['calendar' => $calendar, 'checks' => [], 'versions' => [], 'unlinked' => [], 'myVersionsWithoutDate' => []];
        }

        // 1) Mes tickets à livrer (versions non livrées, ou livrées depuis moins de 30 jours) et tickets WS suivis
        $searches = [
            'mine' => [
                "$scope AND (fixVersion in unreleasedVersions() OR (fixVersion is EMPTY AND statusCategory != Done) OR resolved >= -30d) ORDER BY key ASC",
                2000, false, self::LINK_FIELDS,
            ],
            'ws' => [$this->wsTrackedJql($deliveryScope, $projects), 1000, false, self::LINK_FIELDS],
        ];
        $results = $this->jira->searchMany($searches);
        foreach ($results as $result) {
            if ($result instanceof AtlassianException) {
                throw $result;
            }
        }
        [$mine, $tracked] = [$results['mine'], $results['ws']];

        // 2) Tickets WS liés à mes tickets (leurs versions ne sont pas fournies par le lien)
        $wsIssues = [];
        foreach ($tracked as $issue) {
            $wsIssues[$issue->key] = $issue;
        }
        $missing = [];
        foreach ($mine as $issue) {
            foreach ($issue->links as $link) {
                if (\in_array($link['projectKey'], $wsKeys, true) && !isset($wsIssues[$link['key']])) {
                    $missing[$link['key']] = true;
                }
            }
        }
        $wsIssues += $this->fetchByKeys(array_keys($missing));

        // 3) Contrôles
        $checks = [];
        $myVersionsWithoutDate = [];
        foreach ($mine as $issue) {
            $seen = [];
            foreach ($issue->links as $link) {
                // Un même ticket WS peut être lié deux fois (deux types de lien) : un seul contrôle
                if (!isset($wsIssues[$link['key']]) || isset($seen[$link['key']])) {
                    continue;
                }
                $seen[$link['key']] = true;
                $check = $this->check($issue, $wsIssues[$link['key']], $link['type'], $calendar);
                if ($check->myVersion && !$check->myDate) {
                    $myVersionsWithoutDate[$check->myVersion] = true;
                }
                $checks[] = $check;
            }
        }
        usort($checks, fn (DeliveryCheck $a, DeliveryCheck $b) => [$a->myDate === null, $a->myDate, $a->myVersion, $a->issue->key] <=> [$b->myDate === null, $b->myDate, $b->myVersion, $b->issue->key]);

        // Tickets WS suivis (board / étiquette) non terminés, sans lien vers un ticket de mes projets
        $unlinked = array_values(array_filter($tracked, fn (Issue $ws) => !$ws->isDone()
            && [] === array_filter($ws->links, fn (array $l) => \in_array($l['projectKey'], $myKeys, true))));

        return [
            'calendar' => $calendar,
            'checks' => $checks,
            // Versions WS à contrôler : celles des tickets WS suivis ou liés (le projet WS peut en contenir d'autres)
            'versions' => $this->compareVersions(array_values($wsIssues), $calendar),
            'unlinked' => $unlinked,
            'myVersionsWithoutDate' => array_keys($myVersionsWithoutDate),
        ];
    }

    private function check(Issue $issue, Issue $ws, string $linkType, DeliveryCalendar $calendar): DeliveryCheck
    {
        $margin = $this->settings->getMarginDays();
        $today = new \DateTimeImmutable('today');

        // Ma version : la première livrée parmi les versions du ticket
        $myVersion = null;
        $myDate = null;
        foreach ($issue->versionDetails as $v) {
            if (null === $myVersion || ($v['releaseDate'] && (!$myDate || $v['releaseDate'] < $myDate))) {
                $myVersion = $v['name'];
                $myDate = $v['releaseDate'];
            }
        }

        // Versions WS : date Confluence (référence), sinon date Jira ; le ticket est livré avec sa dernière version
        $wsVersions = [];
        $wsDate = null;
        foreach ($ws->versionDetails as $v) {
            $prefix = $this->settings->getPrefix($ws->projectKey);
            $confluenceDate = $calendar->dateOf($v['name'], $prefix, $v['id'] ?? null);
            $wsVersions[] = [
                'name' => $v['name'],
                'jiraDate' => $v['releaseDate'],
                'confluenceDate' => $confluenceDate,
                'inConfluence' => [] !== $calendar->find($v['name'], $prefix, $v['id'] ?? null),
                'otherComponents' => $calendar->componentsForNumber($v['name'], $prefix),
            ];
            $date = $confluenceDate ?? $v['releaseDate'];
            if ($date && (!$wsDate || $date > $wsDate)) {
                $wsDate = $date;
            }
        }

        $check = new DeliveryCheck($issue, $myDate, $myVersion, $ws, $linkType, $wsVersions, $wsDate);

        if ($this->scope && [] !== $this->scope->labels && !$this->scope->hasWsLabel($ws->labels)) {
            $check->add(DeliveryCheck::WARNING, sprintf('Le ticket WS n\'a pas l\'étiquette %s (il n\'apparaît pas sur le board).', implode(' / ', $this->scope->labels)));
        }

        if (!$myVersion) {
            $check->add(DeliveryCheck::WARNING, 'Mon ticket n\'a pas de version : impossible de vérifier le calendrier.');
        } elseif (!$myDate) {
            $check->add(DeliveryCheck::WARNING, sprintf('Ma version « %s » n\'a pas de date de livraison dans Jira.', $myVersion));
        }

        if ([] === $wsVersions) {
            $check->add($ws->isDone() ? DeliveryCheck::WARNING : DeliveryCheck::ERROR, 'Le ticket WS n\'a pas de version : date de livraison inconnue.');
        }
        foreach ($wsVersions as $v) {
            if (!$calendar->error && !$v['inConfluence']) {
                $check->add(DeliveryCheck::WARNING, sprintf('Version WS « %s » absente du calendrier Confluence.', $v['name'])
                    .self::componentsHint($v['otherComponents'], $ws->projectKey));
            } elseif ($v['inConfluence'] && !$v['confluenceDate']) {
                $check->add(DeliveryCheck::WARNING, sprintf('Version WS « %s » sans date lisible dans le calendrier Confluence.', $v['name']));
            }
            if ($v['jiraDate'] && $v['confluenceDate'] && $v['jiraDate'] != $v['confluenceDate']) {
                $check->add(DeliveryCheck::WARNING, sprintf(
                    'Version WS « %s » : date Jira %s ≠ date Confluence %s.',
                    $v['name'], $v['jiraDate']->format('d/m/Y'), $v['confluenceDate']->format('d/m/Y'),
                ));
            }
            if ($calendar->error && !$v['jiraDate']) {
                // Calendrier illisible : seule la date Jira est disponible
                $check->add(DeliveryCheck::WARNING, sprintf('Version WS « %s » sans date dans Jira (calendrier Confluence indisponible).', $v['name']));
            }
        }

        if ($myDate && $wsDate) {
            $limit = $myDate->modify(sprintf('-%d days', $margin));
            if ($wsDate > $limit) {
                $check->add(DeliveryCheck::ERROR, $wsDate > $myDate
                    ? sprintf('Livraison WS le %s, APRÈS ma version « %s » du %s.', $wsDate->format('d/m/Y'), $myVersion, $myDate->format('d/m/Y'))
                    : sprintf('Livraison WS le %s : moins de %d jour(s) avant ma version du %s.', $wsDate->format('d/m/Y'), $margin, $myDate->format('d/m/Y')));
            }
        }
        if ($wsDate && $wsDate < $today && !$ws->isDone()) {
            $check->add(DeliveryCheck::WARNING, sprintf('Livraison WS prévue le %s, mais le ticket WS n\'est pas terminé (%s).', $wsDate->format('d/m/Y'), $ws->status));
        }

        return $check;
    }

    /**
     * Versions des tickets WS suivis ou liés (à venir, sans date ou livrées depuis moins de 90 jours),
     * date Jira comparée au calendrier Confluence.
     *
     * @param Issue[] $wsIssues
     */
    private function compareVersions(array $wsIssues, DeliveryCalendar $calendar): array
    {
        $limit = new \DateTimeImmutable('-90 days');
        $versions = [];
        foreach ($wsIssues as $issue) {
            foreach ($issue->versionDetails as $v) {
                $key = $issue->projectKey.'|'.$v['name'];
                $versions[$key] ??= $v + ['projectKey' => $issue->projectKey, 'issues' => []];
                $versions[$key]['issues'][$issue->key] = $issue->key;
            }
        }

        $result = [];
        foreach ($versions as $v) {
            if ($v['released'] && $v['releaseDate'] && $v['releaseDate'] < $limit) {
                continue;
            }
            $prefix = $this->settings->getPrefix($v['projectKey']);
            $rows = $calendar->find($v['name'], $prefix, $v['id'] ?? null);
            $confluenceDate = $calendar->dateOf($v['name'], $prefix, $v['id'] ?? null);
            [$level, $message] = match (true) {
                null !== $calendar->error => ['secondary', null],
                [] === $rows => ['warning', 'Absente du calendrier Confluence'.self::componentsHint($calendar->componentsForNumber($v['name'], $prefix), $v['projectKey'])],
                null === $confluenceDate => ['warning', 'Date illisible dans Confluence'],
                null === $v['releaseDate'] => ['warning', 'Pas de date dans Jira'],
                $v['releaseDate'] != $confluenceDate => ['danger', 'Dates différentes'],
                default => ['success', null],
            };
            $result[] = [
                'projectKey' => $v['projectKey'],
                'name' => $v['name'],
                'released' => $v['released'],
                'jiraDate' => $v['releaseDate'],
                'confluenceDate' => $confluenceDate,
                'rows' => $rows,
                'issues' => array_values($v['issues']),
                'level' => $level,
                'message' => $message,
            ];
        }
        usort($result, fn ($a, $b) => [$a['confluenceDate'] ?? $a['jiraDate'] ?? new \DateTimeImmutable('2999-01-01'), $a['name']] <=> [$b['confluenceDate'] ?? $b['jiraDate'] ?? new \DateTimeImmutable('2999-01-01'), $b['name']]);

        return $result;
    }

    /**
     * Tickets WS suivis : ceux du board (ou portant l'étiquette), non terminés ou résolus depuis moins de 30 jours ;
     * à défaut, les tickets WS non terminés assignés à un membre suivi ou dans un sprint actif.
     *
     * @param Project[] $projects
     */
    private function wsTrackedJql(DeliveryScope $deliveryScope, array $projects): string
    {
        if ($tracked = $deliveryScope->wsTrackedJql()) {
            return "$tracked AND (statusCategory != Done OR resolved >= -30d) ORDER BY key ASC";
        }
        $wsKeys = $deliveryScope->wsKeys;

        $conditions = [];
        $members = array_map(fn ($d) => $d->getJiraAccountId(), $this->developers->findBy(['active' => true]));
        if ([] !== $members) {
            $conditions[] = 'assignee in ('.JiraClient::quoteList($members).')';
        }
        $sprintIds = [];
        $this->sprints->prefetch($projects, ['active']);
        foreach ($projects as $project) {
            foreach ($this->sprints->getActiveSprints($project) as $sprint) {
                $sprintIds[$sprint->id] = $sprint->id;
            }
        }
        if ([] !== $sprintIds) {
            $conditions[] = 'sprint in ('.implode(', ', $sprintIds).')';
        }
        $filter = [] !== $conditions ? ' AND ('.implode(' OR ', $conditions).')' : ' AND sprint in openSprints()';

        return sprintf('project in (%s) AND statusCategory != Done%s ORDER BY key ASC', JiraClient::quoteList($wsKeys), $filter);
    }

    /**
     * @param string[] $keys
     *
     * @return array<string, Issue>
     */
    private function fetchByKeys(array $keys): array
    {
        $searches = [];
        foreach (array_chunk($keys, 100) as $i => $chunk) {
            $searches['c'.$i] = ['key in ('.implode(', ', $chunk).')', 100, false, self::LINK_FIELDS];
        }
        $issues = [];
        foreach ($this->jira->searchMany($searches) as $result) {
            if ($result instanceof AtlassianException) {
                throw $result;
            }
            foreach ($result as $issue) {
                $issues[$issue->key] = $issue;
            }
        }

        return $issues;
    }

    /** « (trouvée pour WS, WS-REF : indiquez le composant du projet WSC dans le paramétrage) » */
    public static function componentsHint(array $components, string $projectKey): string
    {
        return [] === $components ? '' : sprintf(
            ' (numéro trouvé pour %s : indiquez le composant du projet %s dans le paramétrage des livraisons)',
            implode(', ', $components), $projectKey,
        );
    }
}
