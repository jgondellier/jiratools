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

    public function __construct(
        private readonly DeliverySettings $settings,
        private readonly JiraClient $jira,
        private readonly ConfluenceClient $confluence,
        private readonly SprintService $sprints,
        private readonly DeveloperRepository $developers,
        private readonly DeliveryTableParser $parser,
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
    public function getReport(array $projects): array
    {
        $calendar = $this->getCalendar();
        $wsKeys = $this->settings->getWsKeys();
        $scope = ProjectScope::jqlForAll($projects);
        $myKeys = ProjectScope::jiraKeys($projects);
        if (!$scope || [] === $wsKeys) {
            return ['calendar' => $calendar, 'checks' => [], 'versions' => [], 'unlinked' => [], 'myVersionsWithoutDate' => []];
        }

        // 1) Mes tickets à livrer (versions non livrées, ou livrées depuis moins de 30 jours) et tickets WS en cours
        $searches = [
            'mine' => [
                "$scope AND (fixVersion in unreleasedVersions() OR (fixVersion is EMPTY AND statusCategory != Done) OR resolved >= -30d) ORDER BY key ASC",
                2000, false, self::LINK_FIELDS,
            ],
            'ws' => [$this->wsInProgressJql($wsKeys, $projects), 1000, false, self::LINK_FIELDS],
        ];
        $results = $this->jira->searchMany($searches);
        foreach ($results as $result) {
            if ($result instanceof AtlassianException) {
                throw $result;
            }
        }
        [$mine, $wsInProgress] = [$results['mine'], $results['ws']];

        // 2) Tickets WS liés à mes tickets (leurs versions ne sont pas fournies par le lien) + versions Jira WS
        $linkedKeys = [];
        foreach ($mine as $issue) {
            foreach ($issue->links as $link) {
                if (\in_array($link['projectKey'], $wsKeys, true)) {
                    $linkedKeys[$link['key']] = true;
                }
            }
        }
        $wsIssues = $this->fetchByKeys(array_keys($linkedKeys));
        $versionsByProject = $this->jira->getVersionsMany($wsKeys);

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

        // Tickets WS non liés à un ticket de mes projets
        $unlinked = array_values(array_filter($wsInProgress, fn (Issue $ws) => [] === array_filter($ws->links, fn (array $l) => \in_array($l['projectKey'], $myKeys, true))));

        return [
            'calendar' => $calendar,
            'checks' => $checks,
            'versions' => $this->compareVersions($versionsByProject, $calendar),
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
            $confluenceDate = $calendar->dateOf($v['name'], $prefix);
            $wsVersions[] = [
                'name' => $v['name'],
                'jiraDate' => $v['releaseDate'],
                'confluenceDate' => $confluenceDate,
                'inConfluence' => [] !== $calendar->find($v['name'], $prefix),
                'ambiguous' => $calendar->ambiguousComponents($v['name'], $prefix),
            ];
            $date = $confluenceDate ?? $v['releaseDate'];
            if ($date && (!$wsDate || $date > $wsDate)) {
                $wsDate = $date;
            }
        }

        $check = new DeliveryCheck($issue, $myDate, $myVersion, $ws, $linkType, $wsVersions, $wsDate);

        if (!$myVersion) {
            $check->add(DeliveryCheck::WARNING, 'Mon ticket n\'a pas de version : impossible de vérifier le calendrier.');
        } elseif (!$myDate) {
            $check->add(DeliveryCheck::WARNING, sprintf('Ma version « %s » n\'a pas de date de livraison dans Jira.', $myVersion));
        }

        if ([] === $wsVersions) {
            $check->add($ws->isDone() ? DeliveryCheck::WARNING : DeliveryCheck::ERROR, 'Le ticket WS n\'a pas de version : date de livraison inconnue.');
        }
        foreach ($wsVersions as $v) {
            if ([] !== $v['ambiguous']) {
                $check->add(DeliveryCheck::WARNING, sprintf(
                    'Version WS « %s » présente pour plusieurs composants (%s) : indiquez le composant du projet %s dans le paramétrage des livraisons.',
                    $v['name'], implode(', ', $v['ambiguous']), $ws->projectKey,
                ));
                continue;
            }
            if (!$calendar->error && !$v['inConfluence']) {
                $check->add(DeliveryCheck::WARNING, sprintf('Version WS « %s » absente du calendrier Confluence.', $v['name']));
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
     * Versions Jira WS (non archivées, à venir ou livrées depuis moins de 90 jours) comparées au calendrier.
     */
    private function compareVersions(array $versionsByProject, DeliveryCalendar $calendar): array
    {
        $limit = new \DateTimeImmutable('-90 days');
        $result = [];
        foreach ($versionsByProject as $projectKey => $versions) {
            $prefix = $this->settings->getPrefix($projectKey);
            foreach ($versions as $v) {
                if ($v['archived'] || ($v['released'] && $v['releaseDate'] && $v['releaseDate'] < $limit)) {
                    continue;
                }
                $rows = $calendar->find($v['name'], $prefix);
                $confluenceDate = $calendar->dateOf($v['name'], $prefix);
                $ambiguous = $calendar->ambiguousComponents($v['name'], $prefix);
                [$level, $message] = match (true) {
                    null !== $calendar->error => ['secondary', null],
                    [] !== $ambiguous => ['warning', 'Plusieurs composants possibles ('.implode(', ', $ambiguous).') : précisez le composant du projet'],
                    [] === $rows => ['warning', 'Absente du calendrier Confluence'],
                    null === $confluenceDate => ['warning', 'Date illisible dans Confluence'],
                    null === $v['releaseDate'] => ['warning', 'Pas de date dans Jira'],
                    $v['releaseDate'] != $confluenceDate => ['danger', 'Dates différentes'],
                    default => ['success', null],
                };
                $result[] = [
                    'projectKey' => $projectKey,
                    'name' => $v['name'],
                    'released' => $v['released'],
                    'jiraDate' => $v['releaseDate'],
                    'confluenceDate' => $confluenceDate,
                    'rows' => $rows,
                    'level' => $level,
                    'message' => $message,
                ];
            }
        }
        usort($result, fn ($a, $b) => [$a['confluenceDate'] ?? $a['jiraDate'] ?? new \DateTimeImmutable('2999-01-01'), $a['name']] <=> [$b['confluenceDate'] ?? $b['jiraDate'] ?? new \DateTimeImmutable('2999-01-01'), $b['name']]);

        return $result;
    }

    /**
     * Tickets WS "sur le board" : non terminés, et assignés à un membre suivi ou dans un sprint actif de mes boards.
     *
     * @param Project[] $projects
     */
    private function wsInProgressJql(array $wsKeys, array $projects): string
    {
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
}
