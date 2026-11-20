<?php

namespace App\Tests\Functional;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Client HTTP simulant Jira / Confluence pour les tests fonctionnels.
 */
class FakeJiraHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(function (string $method, string $url): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_starts_with($url, 'https://gitlab.test/')) {
                $data = $this->gitlab($path, $query);
            } else {
            $data = match (true) {
                (bool) preg_match('#/rest/api/2/issue/([A-Z]+-\d+)$#', $path, $m) => $this->issue($m[1]),
                str_ends_with($path, '/rest/api/2/search/jql') => ['issues' => $this->issues($query['jql'] ?? ''), 'isLast' => true],
                (bool) preg_match('#/project/\w+/statuses$#', $path) => [['name' => 'Story', 'statuses' => [
                    ['name' => 'En cours', 'statusCategory' => ['key' => 'indeterminate', 'name' => 'En cours']],
                    ['name' => 'Code Review', 'statusCategory' => ['key' => 'indeterminate', 'name' => 'En cours']],
                    ['name' => 'Terminé', 'statusCategory' => ['key' => 'done', 'name' => 'Terminé']],
                ]]],
                (bool) preg_match('#/rest/api/content/\d+$#', $path) && str_contains($query['expand'] ?? '', 'body.storage') => [
                    'id' => '777', 'title' => 'Calendrier des livraisons', 'version' => ['number' => 1],
                    'body' => ['storage' => ['value' => '<table><tr><th>Date</th><th>Version</th></tr><tr><td>05/11/2026</td><td>WS-Commande 1.4.2</td></tr></table>']],
                    '_links' => ['base' => 'https://jira.test/wiki', 'webui' => '/pages/777'],
                ],
                str_ends_with($path, '/versions') => [
                    ['id' => 1, 'name' => '1.4.2', 'released' => false, 'releaseDate' => '2026-11-05'],
                    ['id' => 2, 'name' => '1.5.0', 'released' => false],
                ],
                str_ends_with($path, '/rest/api/2/myself'), str_ends_with($path, '/rest/api/user/current') => ['displayName' => 'Testeur'],
                (bool) preg_match('#/board/\d+/sprint$#', $path) => ['isLast' => true, 'values' => 'active' === ($query['state'] ?? '') ? [[
                    'id' => 5, 'name' => 'Sprint 5', 'state' => 'active', 'originBoardId' => 42,
                    'startDate' => (new \DateTimeImmutable('-5 days'))->format(DATE_ATOM),
                    'endDate' => (new \DateTimeImmutable('+9 days'))->format(DATE_ATOM),
                ]] : []],
                default => null,
            };
            }

            return null === $data
                ? new MockResponse('{"errorMessages":["introuvable"]}', ['http_code' => 404])
                : new MockResponse(json_encode($data));
        });
    }

    /**
     * GitLab simulé : develop contient une merge request (APP-1), un commit direct citant un ticket
     * inexistant (APP-404) et un commit direct sans ticket.
     */
    private function gitlab(string $path, array $query = []): ?array
    {
        $commit = fn (string $sha, string $message, int $parents = 1) => [
            'id' => $sha, 'short_id' => substr($sha, 0, 8), 'title' => strtok($message, "\n"), 'message' => $message,
            'author_name' => 'Alice', 'committed_date' => '2026-11-10T10:00:00+01:00',
            'web_url' => 'https://gitlab.test/groupe/portail/-/commit/'.$sha, 'parent_ids' => array_fill(0, $parents, 'p'),
        ];

        return match (true) {
            str_ends_with($path, '/api/v4/user') => ['name' => 'Alice Martin', 'username' => 'amartin'],
            // Tags : v1.4.2 (MR !20 pour APP-2, commit direct APP-3, commit direct sans ticket), v1.4.1 (premier tag)
            str_ends_with($path, '/repository/tags') => [
                ['name' => 'v1.4.2', 'commit' => ['id' => 't2', 'committed_date' => '2026-11-04T10:00:00+01:00']],
                ['name' => 'v1.4.1', 'commit' => ['id' => 't1', 'committed_date' => '2026-10-01T10:00:00+01:00']],
            ],
            str_ends_with($path, '/repository/commits') && 'v1.4.1..v1.4.2' === ($query['ref_name'] ?? '') => [
                $commit('m5m5m5m5m5', "Merge branch 'feature/APP-2-filtre' into 'develop'", 2),
                $commit('d5d5d5d5d5', 'Filtre par date'),
                $commit('d6d6d6d6d6', 'Typo'),
                $commit('d7d7d7d7d7', 'APP-3 libellé'),
            ],
            str_ends_with($path, '/repository/commits') && 'v1.4.1' === ($query['ref_name'] ?? '') => [
                $commit('i1i1i1i1i1', 'Initialisation APP-5'),
            ],
            str_ends_with($path, '/repository/commits') => [
                $commit('c3c3c3c3c3', "Merge branch 'feature/APP-1-export' into 'develop'\n\nSee merge request groupe/portail!12", 2),
                $commit('c2c2c2c2c2', 'Export CSV'),
                $commit('c1c1c1c1c1', 'Hotfix libellé'),
                $commit('c0c0c0c0c0', 'APP-404 coquille'),
            ],
            str_ends_with($path, '/merge_requests') => [
                ['iid' => 12, 'title' => 'Export CSV', 'description' => '', 'source_branch' => 'feature/app-1-export', 'author' => ['name' => 'Alice'],
                    'merged_at' => '2026-11-10T10:00:00+01:00', 'web_url' => 'https://gitlab.test/groupe/portail/-/merge_requests/12',
                    'merge_commit_sha' => 'c3c3c3c3c3', 'squash_commit_sha' => null, 'sha' => 'c2c2c2c2c2'],
                ['iid' => 20, 'title' => 'Filtre par date', 'description' => '', 'source_branch' => 'feature/APP-2-filtre', 'author' => ['name' => 'Bob'],
                    'merged_at' => '2026-11-03T10:00:00+01:00', 'web_url' => 'https://gitlab.test/groupe/portail/-/merge_requests/20',
                    'merge_commit_sha' => 'm5m5m5m5m5', 'squash_commit_sha' => null, 'sha' => 'd5d5d5d5d5'],
                ['iid' => 11, 'title' => 'APP-50 déjà en production', 'source_branch' => 'feature/APP-50', 'merge_commit_sha' => 'ancien', 'sha' => 'ancien2',
                    'web_url' => 'https://gitlab.test/groupe/portail/-/merge_requests/11'],
            ],
            str_ends_with($path, '/merge_requests/12/commits') => [['id' => 'c2c2c2c2c2', 'message' => 'Export CSV']],
            str_ends_with($path, '/merge_requests/20/commits') => [['id' => 'd5d5d5d5d5', 'short_id' => 'd5d5d5d5', 'title' => 'Filtre par date', 'message' => 'Filtre par date', 'author_name' => 'Bob']],
            (bool) preg_match('#/api/v4/projects/[^/]+$#', $path) => [
                'id' => 99, 'name_with_namespace' => 'Groupe / Portail', 'path_with_namespace' => 'groupe/portail',
                'web_url' => 'https://gitlab.test/groupe/portail', 'default_branch' => 'develop',
            ],
            default => null,
        };
    }

    private function issue(string $key): ?array
    {
        $version = fn (int $id) => match ($id) {
            1 => ['id' => '1', 'name' => '1.4.2', 'released' => false, 'releaseDate' => '2026-11-05'],
            default => ['id' => '2', 'name' => '1.5.0', 'released' => false],
        };
        $done = ['name' => 'Terminé', 'statusCategory' => ['key' => 'done']];
        $fields = match ($key) {
            'APP-1' => ['summary' => 'Export CSV', 'status' => $done, 'fixVersions' => [$version(1)]],
            'APP-2' => ['summary' => 'Filtre par date', 'status' => $done, 'fixVersions' => [$version(1)]],
            'APP-3' => ['summary' => 'Libellé', 'status' => $done, 'fixVersions' => [$version(2)]],
            'APP-9' => ['summary' => 'Annoncé sans code', 'status' => ['name' => 'À faire', 'statusCategory' => ['key' => 'new']], 'fixVersions' => [$version(1)]],
            default => null,
        };

        return null === $fields ? null : ['key' => $key, 'fields' => $fields + ['issuetype' => ['name' => 'Story'], 'project' => ['key' => 'APP']]];
    }

    private function issues(string $jql): array
    {
        if (str_starts_with($jql, 'fixVersion in (1)')) {
            return [$this->issue('APP-1'), $this->issue('APP-9')];
        }
        // Suivi par projet : tickets des versions non livrées ou taggées
        if (str_contains($jql, 'AND fixVersion in (1, 2)')) {
            return [$this->issue('APP-1'), $this->issue('APP-2'), $this->issue('APP-3'), $this->issue('APP-9')];
        }
        if (!str_contains($jql, 'statusCategory = "In Progress"')) {
            return [];
        }

        return [[
            'key' => 'APP-1',
            'fields' => [
                'summary' => 'Ticket qui traîne',
                'status' => ['name' => 'Code Review', 'statusCategory' => ['key' => 'indeterminate']],
                'issuetype' => ['name' => 'Story'],
                'assignee' => str_contains($jql, 'assignee is EMPTY') ? null : ['accountId' => 'acc-alice', 'displayName' => 'Alice'],
                'project' => ['key' => 'APP'],
                'components' => [['name' => 'Portail']],
                'labels' => ['export'],
                'created' => (new \DateTimeImmutable('-20 days'))->format(DATE_ATOM),
            ],
            'changelog' => ['histories' => [[
                'created' => (new \DateTimeImmutable('-10 days'))->format(DATE_ATOM),
                'items' => [['field' => 'status']],
            ]]],
        ]];
    }
}
