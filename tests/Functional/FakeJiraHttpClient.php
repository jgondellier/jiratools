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

            $data = match (true) {
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

            return null === $data
                ? new MockResponse('{"errorMessages":["introuvable"]}', ['http_code' => 404])
                : new MockResponse(json_encode($data));
        });
    }

    private function issues(string $jql): array
    {
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
