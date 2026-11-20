<?php

namespace App\Tests\LocalUpdate;

use App\LocalUpdate\ArchiveDownloader;
use App\LocalUpdate\ArchiveRequest;
use App\LocalUpdate\LocalUpdateException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ArchiveDownloaderTest extends TestCase
{
    use TempDirTrait;

    private function download(callable $responder, string $proxy = 'http://alice:secret@proxy.entreprise:8080'): string
    {
        $file = $this->tmp('dl').'/archive.zip';
        @mkdir(\dirname($file), 0777, true);
        (new ArchiveDownloader(new MockHttpClient($responder), $proxy, null, false))->download(
            new ArchiveRequest('https://api.github.com/repos/acme/app/zipball/main', ['Authorization' => 'Bearer jeton']),
            $file,
        );

        return (string) file_get_contents($file);
    }

    public function testFollowsRedirectWithoutSendingTheTokenToAnotherHost(): void
    {
        $seen = [];
        $content = $this->download(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $auth = array_filter($options['headers'], fn ($h) => str_starts_with(strtolower($h), 'authorization'));
            $seen[parse_url($url, PHP_URL_HOST)] = [] !== $auth;

            return str_contains($url, 'api.github.com')
                ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://codeload.github.com/acme/app/legacy.zip/main']])
                : new MockResponse('ZIP');
        });

        self::assertSame('ZIP', $content);
        self::assertSame(['api.github.com' => true, 'codeload.github.com' => false], $seen);
    }

    public function testProxyRefusingTheRedirectHostIsNamed(): void
    {
        try {
            $this->download(fn (string $method, string $url) => str_contains($url, 'api.github.com')
                ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://codeload.github.com/acme/app/legacy.zip/main']])
                : new MockResponse('', ['error' => 'CONNECT tunnel failed, response 403']));
            self::fail('Exception attendue');
        } catch (LocalUpdateException $e) {
            self::assertStringContainsString('depuis codeload.github.com (redirigé par api.github.com)', $e->getMessage());
            self::assertStringContainsString('refuse l\'accès à codeload.github.com', $e->getMessage());
            self::assertStringContainsString('http://proxy.entreprise:8080 (APP_HTTP_PROXY)', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage(), 'Mot de passe du proxy jamais affiché');
        }
    }

    public function testProxyAskingForAuthentication(): void
    {
        $this->expectException(LocalUpdateException::class);
        $this->expectExceptionMessage('demande une authentification');
        $this->download(fn () => new MockResponse('', ['error' => 'CONNECT tunnel failed, response 407']), 'http://proxy.entreprise:8080');
    }
}
