<?php

namespace App\Release;

use App\Atlassian\AtlassianException;
use App\Delivery\DeliverySettings;
use App\GitLab\GitLabClient;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;

/**
 * Contenu d'une MEP : tickets Jira cités par les merge requests et commits de la branche livrée
 * (develop) qui ne sont pas encore en production, et contrôle que Jira est à jour.
 */
class ReleaseContentService
{
    public function __construct(
        private readonly GitLabClient $gitlab,
        private readonly JiraClient $jira,
        private readonly ReleaseContentSettings $settings,
        private readonly DeliverySettings $deliverySettings,
        private readonly ProjectRepository $projects,
    ) {
    }

    /** Clés des projets Jira paramétrés (projets actifs) */
    public function getMyProjectKeys(): array
    {
        $keys = array_map(fn ($p) => $p->getJiraProjectKey(), $this->projects->findBy(['active' => true]));

        return array_values(array_unique(array_filter($keys)));
    }

    /** Clés de projets reconnues dans les commits : paramétrage, à défaut mes projets + projets WS */
    public function getTicketProjectKeys(): array
    {
        return $this->settings->getTicketProjects()
            ?: array_values(array_unique(array_merge($this->getMyProjectKeys(), $this->deliverySettings->getWsKeys())));
    }

    /**
     * Versions non livrées de mes projets, de la plus proche à la plus lointaine (sans date à la fin).
     *
     * @return array<int, array{id: string, name: string, released: bool, archived: bool, releaseDate: ?\DateTimeImmutable, projectKey: string}>
     */
    public function getCandidateVersions(): array
    {
        $keys = $this->getMyProjectKeys();
        if ([] === $keys) {
            return [];
        }
        $versions = array_filter(
            array_merge(...array_values($this->jira->getVersionsMany($keys))),
            fn (array $v) => !$v['released'] && !$v['archived'],
        );
        usort($versions, fn ($a, $b) => [null === $a['releaseDate'], $a['releaseDate'], $a['projectKey'], $a['name']]
            <=> [null === $b['releaseDate'], $b['releaseDate'], $b['projectKey'], $b['name']]);

        return array_values($versions);
    }

    /**
     * Version(s) proposée(s) par défaut : la prochaine date de livraison (toutes les versions de cette date).
     *
     * @return string[]
     */
    public static function defaultVersionIds(array $candidates): array
    {
        $first = $candidates[0]['releaseDate'] ?? null;
        if (null === $first) {
            return [];
        }

        return array_values(array_map(fn ($v) => $v['id'], array_filter($candidates, fn ($v) => $v['releaseDate'] == $first)));
    }

    /**
     * Lit un dépôt : commits "référence..branche", merge requests fusionnées correspondantes, clés Jira citées.
     *
     * @param array{path: string, reference: ?string, branch: ?string} $repository
     */
    public function scanRepository(array $repository, ?array $projectKeys = null): RepositoryScan
    {
        $projectKeys ??= $this->getTicketProjectKeys();
        $reference = ($repository['reference'] ?? null) ?: $this->settings->getReference();
        $branch = ($repository['branch'] ?? null) ?: $this->settings->getBranch();
        $scan = new RepositoryScan($repository['path'], '' !== $reference ? $reference : null, $branch);

        try {
            $project = $this->gitlab->getProject($repository['path']);
            $scan->name = $project['name'];
            $scan->webUrl = $project['webUrl'];

            if (null === $scan->reference) {
                $scan->reference = $this->gitlab->getLatestTag($project['id']);
                if (null === $scan->reference) {
                    $scan->error = 'Aucun tag dans ce dépôt : indiquez la branche ou le tag de production.';

                    return $scan;
                }
            }

            $this->fillScan($scan, $project['id'], $projectKeys);
        } catch (AtlassianException $e) {
            $scan->error = $e->getMessage();
        }

        return $scan;
    }

    /**
     * Lit l'intervalle "from..to" d'un dépôt déjà résolu (ex. : tag précédent..tag, dernier tag..develop).
     *
     * @param array{id: int, name: string, path: string, webUrl: string} $gitProject
     * @param array<int, array>|null                                       $mergeRequests merge requests déjà lues (sinon lues ici)
     */
    public function scanRange(array $gitProject, ?string $from, string $to, array $projectKeys, ?array $mergeRequests = null): RepositoryScan
    {
        $scan = new RepositoryScan($gitProject['path'], $from, $to);
        $scan->name = $gitProject['name'];
        $scan->webUrl = $gitProject['webUrl'];
        try {
            $this->fillScan($scan, $gitProject['id'], $projectKeys, $mergeRequests);
        } catch (AtlassianException $e) {
            $scan->error = $e->getMessage();
        }

        return $scan;
    }

    /**
     * @param array<int, array>|null $mergeRequests merge requests fusionnées déjà lues (null : lues depuis le plus ancien commit)
     *
     * @throws AtlassianException
     */
    private function fillScan(RepositoryScan $scan, int $projectId, array $projectKeys, ?array $mergeRequests = null): void
    {
        $commits = $this->gitlab->getCommitsBetween($projectId, $scan->reference ?? '', $scan->branch);
        $scan->commitCount = \count($commits);
        if ([] === $commits) {
            return;
        }

        $bySha = [];
        foreach ($commits as $commit) {
            $bySha[$commit['sha']] = $commit;
        }

        // Merge requests (vers n'importe quelle branche : develop, hotfix…) dont le commit de fusion,
        // le commit "squash" ou le dernier commit (fast-forward) est dans l'intervalle
        if (null === $mergeRequests) {
            $dates = array_filter(array_column($commits, 'date'));
            $oldest = [] !== $dates ? min($dates) : new \DateTimeImmutable('-1 year');
            $mergeRequests = $this->gitlab->getMergedMergeRequests($projectId, null, $oldest->modify('-1 day'));
        }
        $mergeRequests = array_values(array_filter($mergeRequests, fn (array $mr) => [] !== array_intersect($mr['shas'], array_keys($bySha))));
        $mrCommits = [] !== $mergeRequests ? $this->gitlab->getMergeRequestCommits($projectId, array_column($mergeRequests, 'iid')) : [];

        $covered = [];
        foreach ($mergeRequests as $mr) {
            $texts = [$mr['title'], $mr['sourceBranch'], $mr['description']];
            $commitList = [];
            foreach ($mrCommits[$mr['iid']] ?? [] as $c) {
                $texts[] = $c['message'];
                $covered[$c['sha']] = true;
                $commitList[] = $c + ['keys' => TicketKeyExtractor::extract($c['message'], $projectKeys)];
            }
            foreach ($mr['shas'] as $sha) {
                if (isset($bySha[$sha])) {
                    $texts[] = $bySha[$sha]['message'];
                    $covered[$sha] = true;
                }
            }
            $scan->mergeRequests[] = [
                'iid' => $mr['iid'],
                'title' => $mr['title'],
                'sourceBranch' => $mr['sourceBranch'],
                'targetBranch' => $mr['targetBranch'] ?? '',
                'author' => $mr['author'],
                'mergedAt' => $mr['mergedAt'],
                'url' => $mr['url'],
                'keys' => TicketKeyExtractor::extract(implode("\n", $texts), $projectKeys),
                'commits' => \count($commitList),
                'commitList' => $commitList,
            ];
        }

        // Commits poussés sans merge request (les commits de fusion "Merge branch..." sont ignorés)
        foreach ($commits as $commit) {
            if (isset($covered[$commit['sha']]) || $commit['merge']) {
                continue;
            }
            $commit['keys'] = TicketKeyExtractor::extract($commit['message'], $projectKeys);
            $scan->directCommits[] = $commit;
        }
    }

    /**
     * @param string[]|null $targetVersionIds versions de la MEP (null : prochaine date de livraison)
     */
    public function getReport(?array $targetVersionIds = null): ReleaseContent
    {
        $projectKeys = $this->getTicketProjectKeys();
        $candidates = $this->getCandidateVersions();
        $targetVersionIds ??= self::defaultVersionIds($candidates);

        // Seules les versions non livrées de mes projets peuvent être choisies
        $targetVersions = [];
        foreach ($candidates as $v) {
            if (\in_array($v['id'], $targetVersionIds, true)) {
                $targetVersions[$v['id']] = $v['name'];
            }
        }

        $content = new ReleaseContent($projectKeys, $candidates, $targetVersions);
        foreach ($this->getRepositories() as $repository) {
            $content->repositories[] = $this->scanRepository($repository, $projectKeys);
        }

        return $this->fillTickets($content);
    }

    /**
     * Dépôts lus pour la MEP : ceux du paramétrage, puis ceux des projets actifs (sans doublon).
     *
     * @return array<int, array{path: string, reference: ?string, branch: ?string}>
     */
    public function getRepositories(): array
    {
        $repositories = $this->settings->getRepositories();
        $paths = array_map(fn (array $r) => mb_strtolower($r['path']), $repositories);
        foreach ($this->projects->findBy(['active' => true], ['name' => 'ASC']) as $project) {
            $path = $project->getGitlabRepository();
            if (null !== $path && !\in_array(mb_strtolower($path), $paths, true)) {
                $repositories[] = ['path' => $path, 'reference' => null, 'branch' => null];
                $paths[] = mb_strtolower($path);
            }
        }

        return $repositories;
    }

    /**
     * Tickets cités par les dépôts lus, contrôle dans Jira, tickets des versions sans code, code sans ticket.
     */
    public function fillTickets(ReleaseContent $content): ReleaseContent
    {
        $targetVersions = $content->targetVersions;

        // Tickets cités et leurs provenances
        $tickets = [];
        foreach ($content->repositories as $scan) {
            foreach ($scan->mergeRequests as $mr) {
                if ([] === $mr['keys']) {
                    $content->untracked[] = ['repository' => $scan->getLabel(), 'type' => 'mr', 'label' => sprintf('!%d %s', $mr['iid'], $mr['title']), 'detail' => $mr['sourceBranch'], 'url' => $mr['url'], 'author' => $mr['author'], 'date' => $mr['mergedAt']];
                }
                foreach ($mr['keys'] as $key) {
                    $tickets[$key][] = ['repository' => $scan->getLabel(), 'type' => 'mr', 'label' => sprintf('!%d %s', $mr['iid'], $mr['title']), 'url' => $mr['url'], 'author' => $mr['author'], 'date' => $mr['mergedAt']];
                }
            }
            foreach ($scan->directCommits as $commit) {
                if ([] === $commit['keys']) {
                    $content->untracked[] = ['repository' => $scan->getLabel(), 'type' => 'commit', 'label' => $commit['shortSha'].' '.$commit['title'], 'detail' => null, 'url' => $commit['url'], 'author' => $commit['author'], 'date' => $commit['date']];
                }
                foreach ($commit['keys'] as $key) {
                    $tickets[$key][] = ['repository' => $scan->getLabel(), 'type' => 'commit', 'label' => $commit['shortSha'].' '.$commit['title'], 'url' => $commit['url'], 'author' => $commit['author'], 'date' => $commit['date']];
                }
            }
        }

        if ([] === $tickets && [] === $targetVersions) {
            return $content;
        }

        // Tickets Jira (et parents des sous-tâches, pour la version héritée)
        $issues = [] !== $tickets ? $this->jira->getIssues(array_keys($tickets)) : [];
        $parentKeys = array_diff(array_unique(array_filter(array_map(fn (?Issue $i) => $i?->parentKey, $issues))), array_keys($issues));
        $parents = [] !== $parentKeys ? $this->jira->getIssues(array_values($parentKeys)) : [];
        $parents += $issues;

        $expected = $this->settings->getExpectedStatuses();
        foreach ($tickets as $key => $sources) {
            $issue = $issues[$key] ?? null;
            $ticket = new ReleaseTicket($key, $issue, $issue?->parentKey ? ($parents[$issue->parentKey] ?? null) : null);
            foreach ($sources as $source) {
                $ticket->addSource($source);
            }
            $content->tickets[$key] = $ticket->evaluate($targetVersions, $expected);
        }
        uasort($content->tickets, fn (ReleaseTicket $a, ReleaseTicket $b) => [self::rank($a->getLevel()), $a->key] <=> [self::rank($b->getLevel()), $b->key]);

        // Tickets de la version mais absents du code
        if ([] !== $targetVersions) {
            $inCode = array_keys($content->tickets);
            foreach ($content->tickets as $ticket) {
                // un ticket dont une sous-tâche est dans le code est livré
                if ($ticket->issue?->parentKey) {
                    $inCode[] = $ticket->issue->parentKey;
                }
                // ticket déplacé (nouvelle clé)
                if ($ticket->issue) {
                    $inCode[] = $ticket->issue->key;
                }
            }
            $jql = sprintf('fixVersion in (%s) ORDER BY key ASC', implode(', ', array_map('intval', array_keys($targetVersions))));
            $content->versionJql = $jql;
            foreach ($this->jira->search($jql, 1000) as $issue) {
                if (!\in_array($issue->key, $inCode, true) && !\in_array($issue->parentKey, $inCode, true)) {
                    $content->missing[] = $issue;
                }
            }
        }

        return $content;
    }

    private static function rank(string $level): int
    {
        return match ($level) {
            ReleaseTicket::ERROR => 0,
            ReleaseTicket::WARNING => 1,
            default => 2,
        };
    }
}
