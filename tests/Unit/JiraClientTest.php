<?php

namespace App\Tests\Unit;

use App\Atlassian\AtlassianException;
use App\Atlassian\AtlassianHttp;
use App\Jira\JiraClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class JiraClientTest extends TestCase
{
    private function client(array $responses, string $deployment, string $user = 'me@test.fr', array &$urls = []): JiraClient
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$urls) {
            $urls[] = ['url' => $url, 'headers' => $options['normalized_headers'] ?? []];

            return array_shift($responses);
        });
        $http = new AtlassianHttp($mock, new ArrayAdapter(), null, null, 'Jira', 'https://jira.test/', $user, 'tok', 0);

        return new JiraClient($http, $deployment, 'customfield_10016', 'customfield_10020', 'customfield_10021');
    }

    private static function rawIssue(string $key, array $fields = [], array $histories = []): array
    {
        return ['key' => $key, 'fields' => $fields + [
            'summary' => 'Sujet '.$key,
            'status' => ['name' => 'En cours', 'statusCategory' => ['key' => 'indeterminate']],
            'issuetype' => ['name' => 'Story'],
            'created' => '2026-10-01T10:00:00.000+0200',
            'project' => ['key' => 'APP'],
        ], 'changelog' => ['histories' => $histories]];
    }

    public function testCloudSearchUsesTokenPagination(): void
    {
        $urls = [];
        $client = $this->client([
            new MockResponse(json_encode(['issues' => [self::rawIssue('APP-1')], 'nextPageToken' => 'abc'])),
            new MockResponse(json_encode(['issues' => [self::rawIssue('APP-2')], 'isLast' => true])),
        ], 'cloud', 'me@test.fr', $urls);

        $issues = $client->search('project = APP');

        self::assertCount(2, $issues);
        self::assertStringContainsString('/rest/api/2/search/jql?', $urls[0]['url']);
        self::assertStringContainsString('nextPageToken=abc', $urls[1]['url']);
        self::assertStringStartsWith('Authorization: Basic ', $urls[0]['headers']['authorization'][0]);
    }

    public function testDeploymentAutoDetection(): void
    {
        $http = new AtlassianHttp(new MockHttpClient(), new ArrayAdapter(), null, null, 'Jira', 'https://site.atlassian.net', 'me', 'tok', 0);
        self::assertTrue((new JiraClient($http, 'auto', 'a', 'b', 'c'))->isCloud());

        $http = new AtlassianHttp(new MockHttpClient(), new ArrayAdapter(), null, null, 'Jira', 'https://jira.intranet', '', 'tok', 0);
        self::assertFalse((new JiraClient($http, 'auto', 'a', 'b', 'c'))->isCloud());
    }

    public function testServerSearchUsesStartAtAndBearer(): void
    {
        $urls = [];
        $client = $this->client([
            new MockResponse(json_encode(['issues' => [self::rawIssue('APP-1'), self::rawIssue('APP-2')], 'total' => 3])),
            new MockResponse(json_encode(['issues' => [self::rawIssue('APP-3')], 'total' => 3])),
        ], 'server', '', $urls);

        $issues = $client->search('project = APP');

        self::assertCount(3, $issues);
        self::assertStringContainsString('/rest/api/2/search?', $urls[0]['url']);
        self::assertStringContainsString('startAt=2', $urls[1]['url']);
        self::assertSame(['Authorization: Bearer tok'], $urls[0]['headers']['authorization']);
    }

    public function testMapping(): void
    {
        $client = $this->client([
            new MockResponse(json_encode(['issues' => [self::rawIssue('APP-1', [
                'assignee' => ['accountId' => 'acc-1', 'displayName' => 'Alice'],
                'components' => [['name' => 'Portail']],
                'labels' => ['export'],
                'customfield_10016' => 5,
                'customfield_10021' => [['value' => 'Impediment']],
                'customfield_10020' => [['id' => 3, 'name' => 'Sprint 3']],
            ], [
                ['created' => '2026-10-02T10:00:00.000+0200', 'items' => [['field' => 'status']]],
                ['created' => '2026-10-05T09:30:00.000+0200', 'items' => [['field' => 'assignee'], ['field' => 'status']]],
                ['created' => '2026-10-06T09:30:00.000+0200', 'items' => [['field' => 'description']]],
            ])], 'isLast' => true])),
        ], 'cloud');

        $issue = $client->search('key = APP-1', 10, true)[0];

        self::assertSame('acc-1', $issue->assigneeId);
        self::assertSame(['Portail'], $issue->components);
        self::assertSame(['export'], $issue->labels);
        self::assertSame(['Sprint 3'], $issue->sprints);
        self::assertSame(5.0, $issue->storyPoints);
        self::assertTrue($issue->flagged);
        self::assertTrue($issue->isInProgress());
        self::assertSame('2026-10-05 07:30', $issue->statusSince->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        self::assertSame('https://jira.test/browse/APP-1', $issue->url);
    }

    public function testErrorsAreReadable(): void
    {
        $client = $this->client([
            new MockResponse(json_encode(['errorMessages' => ['Le champ « foo » n\'existe pas.']]), ['http_code' => 400]),
        ], 'cloud');

        $this->expectException(AtlassianException::class);
        $this->expectExceptionMessage('Jira a répondu 400 : Le champ « foo » n\'existe pas.');
        $client->search('foo = bar');
    }

    public function testNotConfigured(): void
    {
        $http = new AtlassianHttp(new MockHttpClient(), new ArrayAdapter(), null, null, 'Jira', '', '', '', 0);
        $this->expectException(AtlassianException::class);
        $this->expectExceptionMessage('n\'est pas configuré');
        $http->get('rest/api/2/myself');
    }

    public function testQuote(): void
    {
        self::assertSame('"a \"b\" c\\\\"', JiraClient::quote('a "b" c\\'));
    }
}
