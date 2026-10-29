<?php

namespace App\Tests\Unit;

use App\Atlassian\AtlassianException;
use App\Atlassian\AtlassianHttp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AtlassianHttpTest extends TestCase
{
    public function testGetManyIsolatesErrorsAndCachesSuccesses(): void
    {
        $calls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$calls) {
            $calls[] = parse_url($url, PHP_URL_PATH);

            return str_contains($url, '/ko')
                ? new MockResponse('{"errorMessages":["Interdit"]}', ['http_code' => 403])
                : new MockResponse(json_encode(['url' => $url]));
        });
        $http = new AtlassianHttp($client, new ArrayAdapter(), null, null, 'Jira', 'https://jira.test', 'u', 't', 60);

        $results = $http->getMany(['a' => ['rest/a'], 'ko' => ['rest/ko'], 'b' => ['rest/b', ['x' => 1]]]);

        self::assertSame(['a', 'ko', 'b'], array_keys($results), 'ordre des clés conservé');
        self::assertStringEndsWith('/rest/a', $results['a']['url']);
        self::assertInstanceOf(AtlassianException::class, $results['ko']);
        self::assertSame(403, $results['ko']->getCode());
        self::assertCount(3, $calls);

        // Deuxième passage : les réponses en succès viennent du cache, l'erreur est redemandée
        $calls = [];
        $http->getMany(['a' => ['rest/a'], 'ko' => ['rest/ko'], 'b' => ['rest/b', ['x' => 1]]]);
        self::assertSame(['/rest/ko'], $calls);
    }

    public function testRequestsAreSentBeforeResponsesAreRead(): void
    {
        $events = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$events) {
            $name = basename(parse_url($url, PHP_URL_PATH));
            $events[] = 'envoi '.$name;
            // Le corps n'est produit qu'au moment où la réponse est lue
            $body = (function () use (&$events, $name) {
                $events[] = 'lecture '.$name;
                yield '{}';
            })();

            return new MockResponse($body);
        });
        $http = new AtlassianHttp($client, new ArrayAdapter(), null, null, 'Jira', 'https://jira.test', 'u', 't', 0);

        $http->getMany(['a' => ['rest/a'], 'b' => ['rest/b'], 'c' => ['rest/c']]);

        self::assertSame(['envoi a', 'envoi b', 'envoi c'], \array_slice($events, 0, 3), 'toutes les requêtes partent avant la première lecture');
        self::assertCount(6, $events);
    }
}
