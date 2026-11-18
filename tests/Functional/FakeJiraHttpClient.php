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
                $data = $this->gitlab($path);
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
                str_ends_with($path, '/versions') => [['id' => 1, 'name' => '1.4.2', 'released' => false, 'releaseDate' => '2026-11-05']],
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
    private function gitlab(string $path): ?array
    {
        $commit = fn (string $sha, string $message, int $parents = 1) => [
            'id' => $sha, 'short_id' => substr($sha, 0, 8), 'title' => strtok($message, "\n"), 'message' => $message,
            'author_name' => 'Alice', 'committed_date' => '2026-11-10T10:00:00+01:00',
            'web_url' => 'https://gitlab.test/groupe/portail/-/commit/'.$sha, 'parent_ids' => array_fill(0, $parents, 'p'),
        ];

        return match (true) {
            str_ends_with($path, '/api/v4/user') => ['name' => 'Alice Martin', 'username' => 'amartin'],
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
                ['iid' => 11, 'title' => 'APP-50 déjà en production', 'source_branch' => 'feature/APP-50', 'merge_commit_sha' => 'ancien', 'sha' => 'ancien2',
                    'web_url' => 'https://gitlab.test/groupe/portail/-/merge_requests/11'],
            ],
            str_ends_with($path, '/merge_requests/12/commits') => [['id' => 'c2c2c2c2c2', 'message' => 'Export CSV']],
            (bool) preg_match('#/api/v4/projects/[^/]+$#', $path) => [
                'id' => 99, 'name_with_namespace' => 'Groupe / Portail', 'path_with_namespace' => 'groupe/portail',
                'web_url' => 'https://gitlab.test/groupe/portail', 'default_branch' => 'develop',
            ],
            default => null,
        };
    }

    private function issue(string $key): ?array
    {
        if ('APP-1' !== $key) {
            return null;
        }

        return [
            'key' => 'APP-1',
            'fields' => [
                'summary' => 'Export CSV',
                'status' => ['name' => 'Terminé', 'statusCategory' => ['key' => 'done']],
                'issuetype' => ['name' => 'Story'],
                'project' => ['key' => 'APP'],
                'fixVersions' => [['id' => '1', 'name' => '1.4.2', 'released' => false, 'releaseDate' => '2026-11-05']],
            ],
        ];
    }

    private function issues(string $jql): array
    {
        if (str_starts_with($jql, 'fixVersion in (1)')) {
            return [$this->issue('APP-1'), [
                'key' => 'APP-9',
                'fields' => ['summary' => 'Annoncé sans code', 'status' => ['name' => 'À faire', 'statusCategory' => ['key' => 'new']], 'project' => ['key' => 'APP']],
            ]];
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
