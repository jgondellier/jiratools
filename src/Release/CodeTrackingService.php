<?php

namespace App\Release;

use App\Atlassian\AtlassianException;
use App\Entity\Project;
use App\GitLab\GitLabClient;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Service\ProjectScope;

/**
 * Suivi du code par projet (un dépôt GitLab par projet) :
 *  - pour chaque ticket, est-il dans develop (pas encore taggé) ou dans un tag de version ?
 *  - le tag correspond-il à la version Jira du ticket ?
 *  - contenu d'un tag : commits, merge requests et tickets, alerte pour le code sans ticket.
 */
class CodeTrackingService
{
    public function __construct(
        private readonly GitLabClient $gitlab,
        private readonly JiraClient $jira,
        private readonly ReleaseContentService $content,
        private readonly ReleaseContentSettings $settings,
    ) {
    }

    public function getProjectReport(Project $project): ProjectCodeReport
    {
        $report = new ProjectCodeReport($project, $this->settings->getBranch());
        if (null === $project->getGitlabRepository()) {
            $report->error = 'Aucun dépôt GitLab pour ce projet : renseignez-le dans Paramétrage → Projets.';

            return $report;
        }

        $keys = $this->projectKeys($project);
        try {
            $git = $this->gitlab->getProject($project->getGitlabRepository());
            $report->repositoryName = $git['name'];
            $report->repositoryUrl = $git['webUrl'];

            $count = $this->settings->getTagCount();
            $tags = $this->gitlab->getTags($git['id'], $count + 1);

            // Merge requests lues une seule fois pour toute la période
            $dates = array_filter(array_column(\array_slice($tags, 0, $count + 1), 'date'));
            $since = ([] !== $dates ? min($dates) : new \DateTimeImmutable('-6 months'))->modify('-1 day');
            $mergeRequests = $this->gitlab->getMergedMergeRequests($git['id'], null, $since);

            $report->develop = $this->content->scanRange($git, $tags[0]['name'] ?? null, $report->branch, $keys, $mergeRequests);
            foreach (\array_slice($tags, 0, $count) as $i => $tag) {
                $previous = $tags[$i + 1]['name'] ?? null;
                $report->tags[] = [
                    'name' => $tag['name'],
                    'date' => $tag['date'],
                    'url' => GitLabClient::tagUrl($git['webUrl'], $tag['name']),
                    'previous' => $previous,
                    'scan' => $this->content->scanRange($git, $previous, $tag['name'], $keys, $mergeRequests),
                    'versions' => [],
                    'problems' => [],
                ];
            }
        } catch (AtlassianException $e) {
            $report->error = $e->getMessage();

            return $report;
        }

        // Emplacement de chaque ticket cité dans le code
        $tickets = [];
        $sources = [];
        $collect = function (RepositoryScan $scan, ?string $tag) use (&$tickets, &$sources): void {
            foreach (self::sourcesOf($scan) as $key => $list) {
                $tickets[$key] ??= ['tags' => [], 'develop' => false];
                null === $tag ? $tickets[$key]['develop'] = true : $tickets[$key]['tags'][] = $tag;
                $sources[$key] = array_merge($sources[$key] ?? [], $list);
            }
        };
        if ($report->develop) {
            $collect($report->develop, null);
        }
        foreach ($report->tags as $tag) {
            $collect($tag['scan'], $tag['name']);
        }

        // Versions Jira du projet ; tickets des versions non livrées ou dont le tag a été lu
        $versions = $this->jira->getVersions($project->getJiraProjectKey());
        $tagNames = $report->getTagNames();
        foreach ($report->tags as $i => $tag) {
            $report->tags[$i]['versions'] = array_values(array_filter($versions, fn (array $v) => VersionNumber::same($tag['name'], $v['name'])));
            if ([] === $report->tags[$i]['versions']) {
                $report->tags[$i]['problems'][] = ['level' => TrackedTicket::WARNING, 'message' => sprintf('Aucune version Jira %s ne correspond au tag.', VersionNumber::of($tag['name']) ?? $tag['name'])];
            }
            foreach ($report->tags[$i]['versions'] as $v) {
                if (!$v['released']) {
                    $report->tags[$i]['problems'][] = ['level' => TrackedTicket::WARNING, 'message' => sprintf('Version Jira « %s » pas encore marquée livrée alors que le tag existe.', $v['name'])];
                }
            }
        }
        $scopeVersionIds = array_values(array_map(fn ($v) => $v['id'], array_filter($versions, fn (array $v) => (!$v['released'] && !$v['archived'])
            || [] !== array_filter($tagNames, fn ($t) => VersionNumber::same($t, $v['name'])))));
        $scopeIssues = [] !== $scopeVersionIds
            ? $this->jira->search(sprintf('%s AND fixVersion in (%s) ORDER BY key ASC', ProjectScope::jql($project), implode(', ', array_map('intval', $scopeVersionIds))), 1000)
            : [];

        $issues = [] !== $tickets ? $this->jira->getIssues(array_keys($tickets)) : [];
        foreach ($scopeIssues as $issue) {
            $issues[$issue->key] ??= $issue;
        }
        $parentKeys = array_diff(array_unique(array_filter(array_map(fn (?Issue $i) => $i?->parentKey, $issues))), array_keys($issues));
        $parents = ([] !== $parentKeys ? $this->jira->getIssues(array_values($parentKeys)) : []) + $issues;

        foreach ($issues as $key => $issue) {
            $tracked = new TrackedTicket($key, $issue, $issue?->parentKey ? ($parents[$issue->parentKey] ?? null) : null);
            $tracked->tags = $tickets[$key]['tags'] ?? [];
            $tracked->inDevelop = $tickets[$key]['develop'] ?? false;
            foreach ($sources[$key] ?? [] as $source) {
                $tracked->addSource($source);
            }
            $report->tickets[$key] = $tracked;
        }

        // Un ticket sans commit dont une sous-tâche est dans le code est livré avec elle
        foreach ($report->tickets as $tracked) {
            $parentKey = $tracked->issue?->parentKey;
            if ($parentKey && isset($report->tickets[$parentKey]) && !isset($tickets[$parentKey])) {
                $parent = $report->tickets[$parentKey];
                $parent->tags = array_values(array_unique(array_merge($parent->tags, $tracked->tags)));
                $parent->inDevelop = $parent->inDevelop || $tracked->inDevelop;
            }
        }

        foreach ($report->tickets as $tracked) {
            $tracked->evaluate($tagNames, $report->branch);
        }
        uasort($report->tickets, fn (TrackedTicket $a, TrackedTicket $b) => [self::rank($a->getLevel()), $a->key] <=> [self::rank($b->getLevel()), $b->key]);

        return $report;
    }

    /**
     * Contenu d'un tag de version (depuis le tag précédent) : merge requests, commits, tickets et contrôle Jira.
     *
     * @return array{content: ?ReleaseContent, tags: array, tag: ?string, previous: ?string, repositoryUrl: ?string, error: ?string}
     */
    public function getTagReport(Project $project, ?string $tagName = null): array
    {
        $result = ['content' => null, 'tags' => [], 'tag' => null, 'previous' => null, 'repositoryUrl' => null, 'error' => null];
        if (null === $project->getGitlabRepository()) {
            $result['error'] = 'Aucun dépôt GitLab pour ce projet : renseignez-le dans Paramétrage → Projets.';

            return $result;
        }

        try {
            $git = $this->gitlab->getProject($project->getGitlabRepository());
            $result['repositoryUrl'] = $git['webUrl'];
            $tags = $this->gitlab->getTags($git['id'], 100);
            $result['tags'] = $tags;
            if ([] === $tags) {
                $result['error'] = 'Aucun tag dans ce dépôt.';

                return $result;
            }
            $index = 0;
            if (null !== $tagName) {
                $index = array_search($tagName, array_column($tags, 'name'), true);
                if (false === $index) {
                    $result['error'] = sprintf('Tag « %s » introuvable parmi les 100 derniers tags.', $tagName);

                    return $result;
                }
            }
            $result['tag'] = $tags[$index]['name'];
            $result['previous'] = $tags[$index + 1]['name'] ?? null;

            // Versions Jira du projet portant le numéro du tag
            $targetVersions = [];
            foreach ($this->jira->getVersions($project->getJiraProjectKey()) as $v) {
                if (VersionNumber::same($result['tag'], $v['name'])) {
                    $targetVersions[$v['id']] = $v['name'];
                }
            }

            $keys = $this->projectKeys($project);
            $content = new ReleaseContent($keys, [], $targetVersions);
            $content->repositories[] = $this->content->scanRange($git, $result['previous'], $result['tag'], $keys);
            $result['content'] = $this->content->fillTickets($content);
        } catch (AtlassianException $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /** Clés reconnues dans les commits : celles du paramétrage (ou mes projets + WS), plus celle du projet */
    private function projectKeys(Project $project): array
    {
        return array_values(array_unique(array_merge([$project->getJiraProjectKey()], $this->content->getTicketProjectKeys())));
    }

    /**
     * Provenances (merge requests, commits) de chaque clé citée dans un intervalle.
     *
     * @return array<string, array<int, array>>
     */
    private static function sourcesOf(RepositoryScan $scan): array
    {
        $sources = [];
        $label = $scan->reference ? $scan->reference.'..'.$scan->branch : $scan->branch;
        foreach ($scan->mergeRequests as $mr) {
            foreach ($mr['keys'] as $key) {
                $sources[$key][] = ['repository' => $label, 'type' => 'mr', 'label' => sprintf('!%d %s', $mr['iid'], $mr['title']), 'url' => $mr['url'], 'author' => $mr['author'], 'date' => $mr['mergedAt']];
            }
        }
        foreach ($scan->directCommits as $commit) {
            foreach ($commit['keys'] as $key) {
                $sources[$key][] = ['repository' => $label, 'type' => 'commit', 'label' => $commit['shortSha'].' '.$commit['title'], 'url' => $commit['url'], 'author' => $commit['author'], 'date' => $commit['date']];
            }
        }

        return $sources;
    }

    private static function rank(string $level): int
    {
        return match ($level) {
            TrackedTicket::ERROR => 0,
            TrackedTicket::WARNING => 1,
            default => 2,
        };
    }
}
