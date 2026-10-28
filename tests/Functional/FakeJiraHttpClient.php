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
                str_ends_with($path, '/rest/api/2/myself'), str_ends_with($path, '/rest/api/user/current') => ['displayName' => 'Testeur'],
                (bool) preg_match('#/board/\d+/sprint$#', $path) => ['isLast' => true, 'values' => 'active' === ($query['state'] ?? '') ? [[
                    'id' => 5, 'name' => 'Sprint 5', 'state' => 'active', 'originBoardId' => 42,
                    'startDate' => (new \DateTimeImmutable('-5 days'))->format(DATE_ATOM),
                    'endDate' => (new \DateTimeImmutable('+9 days'))->format(DATE_ATOM),
                ]] : []],
                str_ends_with($path, '/versions') => [['id' => 1, 'name' => '2.0', 'released' => false]],
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
