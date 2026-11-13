<?php

namespace App\Tests\Functional;

use App\Entity\LocalApp;
use App\LocalUpdate\ArchiveDownloader;
use App\LocalUpdate\CommandRunner;
use App\LocalUpdate\LocalUpdater;
use App\LocalUpdate\ProjectSynchronizer;
use App\LocalUpdate\UpdateOptions;
use App\LocalUpdate\ZipExtractor;
use App\Tests\Unit\LocalUpdate\TempDirTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LocalUpdateTest extends WebTestCase
{
    use TempDirTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testCreateAppAndShowRunForm(): void
    {
        $crawler = $this->client->request('GET', '/mises-a-jour/nouvelle');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form([
            'local_app[name]' => 'Catalogue',
            'local_app[provider]' => 'github',
            'local_app[repository]' => 'acme/catalogue',
            'local_app[ref]' => 'main',
            'local_app[targetDirectory]' => $this->tmp('catalogue'),
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/mises-a-jour');
        $this->client->followRedirect();
        self::assertSelectorTextContains('table', 'Catalogue');

        $app = $this->em->getRepository(LocalApp::class)->findOneBy(['name' => 'Catalogue']);
        $this->client->request('GET', '/mises-a-jour/'.$app->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#run-form', 'doctrine:migrations:migrate');
        self::assertSelectorTextContains('main', 'api.github.com/repos/acme/catalogue/zipball/main');
    }

    public function testRemoteAccessIsRefused(): void
    {
        $this->client->request('GET', '/mises-a-jour', server: ['REMOTE_ADDR' => '10.0.0.5']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testFullUpdate(): void
    {
        $target = $this->tmp('project');
        $this->writeFiles($target, ['.env.local' => 'DATABASE_URL=mine', 'src/Old.php' => 'old']);

        $app = (new LocalApp())->setName('Shop')->setProvider('github')->setRepository('acme/shop')->setRef('main')
            ->setTargetDirectory($target)
            ->setDeployedFiles(['src/Old.php'])
            ->setCommands("php -r \"echo 'migrations ok';\"\n# php bin/console app:import-catalogue\nphp -r \"echo file_get_contents('VERSION');\"");
        $this->em->persist($app);
        $this->em->flush();

        $zip = $this->makeZip(['acme-shop-9f8e7d6/VERSION' => '2.0', 'acme-shop-9f8e7d6/.env.local' => 'git', 'acme-shop-9f8e7d6/src/New.php' => 'new']);
        $requested = [];
        $http = new MockHttpClient(function (string $method, string $url) use ($zip, &$requested) {
            $requested[] = $url;

            return new MockResponse(file_get_contents($zip));
        });
        $updater = new LocalUpdater(new ArchiveDownloader($http), new ZipExtractor(), new ProjectSynchronizer(), new CommandRunner(PHP_BINARY), $this->em, $this->tmp('work'), static::getContainer()->getParameter('kernel.project_dir'));

        $run = $updater->run($app, new UpdateOptions(ref: 'v2.0'));

        self::assertSame(['https://api.github.com/repos/acme/shop/zipball/v2.0'], $requested);
        self::assertSame('success', $run->getStatus(), $run->getLog());
        self::assertSame('acme-shop-9f8e7d6', $run->getVersion());
        self::assertStringEqualsFile($target.'/.env.local', 'DATABASE_URL=mine');
        self::assertFileExists($target.'/src/New.php');
        self::assertFileDoesNotExist($target.'/src/Old.php');
        self::assertStringContainsString('migrations ok', $run->getLog());
        self::assertStringContainsString('2.0', $run->getLog());
        self::assertCount(2, $run->getSummary()['commands']);
        self::assertSame(['VERSION', 'src/New.php'], $app->getDeployedFiles());
        self::assertSame('success', $app->getLastStatus());

        // Commandes seules, la première échoue : arrêt
        $app->setCommands("php -r \"exit(2);\"\nphp -r \"echo 'jamais';\"");
        $run = $updater->run($app, new UpdateOptions(deploy: false));
        self::assertSame('failed', $run->getStatus());
        self::assertStringNotContainsString('jamais', $run->getLog());

        $this->client->request('GET', '/mises-a-jour/execution/'.$run->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#run-log', 'code 2');
    }

    public function testRefusesOwnProjectDirectory(): void
    {
        $app = (new LocalApp())->setName('Moi')->setProvider('github')->setRepository('a/b')->setRef('main')
            ->setTargetDirectory(\dirname(static::getContainer()->getParameter('kernel.project_dir')));
        $this->em->persist($app);
        $this->em->flush();

        $updater = new LocalUpdater(new ArchiveDownloader(new MockHttpClient()), new ZipExtractor(), new ProjectSynchronizer(), new CommandRunner(PHP_BINARY), $this->em, $this->tmp('work'), static::getContainer()->getParameter('kernel.project_dir'));
        $run = $updater->run($app);

        self::assertSame('failed', $run->getStatus());
        self::assertStringContainsString('Jira Tools', $run->getLog());
    }
}
