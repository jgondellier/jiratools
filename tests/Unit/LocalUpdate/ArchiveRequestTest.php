<?php

namespace App\Tests\Unit\LocalUpdate;

use App\Entity\LocalApp;
use App\LocalUpdate\ArchiveRequest;
use App\LocalUpdate\LocalUpdateException;
use PHPUnit\Framework\TestCase;

class ArchiveRequestTest extends TestCase
{
    public function testGithub(): void
    {
        $app = (new LocalApp())->setProvider('github')->setRepository('https://github.com/acme/catalogue.git')->setRef('main')->setToken('ghp_x');
        $request = ArchiveRequest::for($app, 'release/1.2');

        self::assertSame('https://api.github.com/repos/acme/catalogue/zipball/release%2F1.2', $request->url);
        self::assertSame('Bearer ghp_x', $request->headers['Authorization']);
        self::assertStringNotContainsString('ghp_x', $request->describe());
    }

    public function testGitlabSelfHosted(): void
    {
        $app = (new LocalApp())->setProvider('gitlab')->setServerUrl('https://gitlab.intra/')->setRepository('web/shop/catalogue')->setRef('develop')->setToken('glpat');
        $request = ArchiveRequest::for($app);

        self::assertSame('https://gitlab.intra/api/v4/projects/web%2Fshop%2Fcatalogue/repository/archive.zip?sha=develop', $request->url);
        self::assertSame('glpat', $request->headers['PRIVATE-TOKEN']);
    }

    public function testBitbucketAppPassword(): void
    {
        $app = (new LocalApp())->setProvider('bitbucket')->setRepository('acme/catalogue')->setRef('main')->setToken('bob:secret');
        $request = ArchiveRequest::for($app);

        self::assertSame('https://bitbucket.org/acme/catalogue/get/main.zip', $request->url);
        self::assertSame(['bob', 'secret'], $request->basicAuth);
    }

    public function testCustomUrlAndEnvToken(): void
    {
        $_ENV['LU_TEST_TOKEN'] = 'abc';
        $app = (new LocalApp())->setProvider('url')->setRepository('https://git.intra/acme/archive/{ref}.zip')->setRef('v2')->setToken('env:LU_TEST_TOKEN');
        $request = ArchiveRequest::for($app);
        unset($_ENV['LU_TEST_TOKEN']);

        self::assertSame('https://git.intra/acme/archive/v2.zip', $request->url);
        self::assertSame('Bearer abc', $request->headers['Authorization']);
    }

    public function testMissingEnvToken(): void
    {
        $this->expectException(LocalUpdateException::class);
        ArchiveRequest::resolveToken('env:LU_UNDEFINED_TOKEN_VAR');
    }
}
