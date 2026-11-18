<?php

namespace App\Tests\LocalUpdate;

use App\Entity\LocalApp;
use App\Entity\LocalUpdateRun;
use App\LocalUpdate\ArchiveDownloader;
use App\LocalUpdate\CommandRunner;
use App\LocalUpdate\LocalUpdater;
use App\LocalUpdate\ProjectSynchronizer;
use App\LocalUpdate\UpdateOptions;
use App\LocalUpdate\ZipExtractor;
use App\Repository\LocalAppRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LocalUpdateTest extends WebTestCase
{
    use TempDirTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = $this->em();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Mise à jour d'une fausse installation de Jira Tools (dossier temporaire) */
    private function updater(string $installDir, MockHttpClient $http = new MockHttpClient()): LocalUpdater
    {
        return new LocalUpdater(new ArchiveDownloader($http), new ZipExtractor(), new ProjectSynchronizer(), new CommandRunner(PHP_BINARY), $this->em(), $this->tmp('work'), $installDir);
    }

    private function settings(string $commands = ''): LocalApp
    {
        $settings = static::getContainer()->get(LocalAppRepository::class)->getSettings();
        $settings->setCommands($commands);
        $this->em()->flush();

        return $settings;
    }

    public function testDefaultSettingsTargetThisInstallation(): void
    {
        $crawler = $this->client->request('GET', '/mises-a-jour');
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('api.github.com/repos/jgondellier/jiratools/zipball/main', $text);
        self::assertStringContainsString('doctrine:schema:update --force', $text);
        self::assertStringContainsString(str_replace('\\', '/', (string) realpath(static::getContainer()->getParameter('kernel.project_dir'))), $text);

        // Une seule ligne de paramètres, quel que soit le nombre d'accès
        $this->client->request('GET', '/mises-a-jour');
        self::assertCount(1, $this->em()->getRepository(LocalApp::class)->findAll());
    }

    public function testSettingsFormHasNoTargetDirectory(): void
    {
        $crawler = $this->client->request('GET', '/mises-a-jour/parametres');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[name*="targetDirectory"]'));
        self::assertSame('env:GITHUB_TOKEN', $crawler->filter('#local_app_token')->attr('value'));

        $form = $crawler->selectButton('Enregistrer')->form([
            'local_app[repository]' => 'acme/jiratools-fork',
            'local_app[ref]' => 'develop',
            'local_app[token]' => '', // pas de dépendance à GITHUB_TOKEN du poste
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/mises-a-jour');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('api.github.com/repos/acme/jiratools-fork/zipball/develop', $crawler->filter('main')->text());
    }

    public function testRemoteAccessIsRefused(): void
    {
        $this->client->request('GET', '/mises-a-jour', server: ['REMOTE_ADDR' => '10.0.0.5']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testFullUpdateOfTheInstallation(): void
    {
        $install = $this->tmp('jiratools');
        $this->writeFiles($install, ['.env.local' => 'DATABASE_URL=mine', 'src/Old.php' => 'old']);
        $settings = $this->settings("php -r \"echo 'migrations ok in '.basename(getcwd());\"\n# php bin/console cache:clear\nphp -r \"echo file_get_contents('VERSION');\"");
        $settings->setDeployedFiles(['src/Old.php']);

        $zip = $this->makeZip(['jgondellier-jiratools-9f8e7d6/VERSION' => '2.0', 'jgondellier-jiratools-9f8e7d6/.env.local' => 'git', 'jgondellier-jiratools-9f8e7d6/src/New.php' => 'new']);
        $requested = [];
        $http = new MockHttpClient(function (string $method, string $url) use ($zip, &$requested) {
            $requested[] = $url;

            return new MockResponse((string) file_get_contents($zip));
        });

        $run = $this->updater($install, $http)->run($settings, new UpdateOptions(ref: 'v2.0'));

        self::assertSame(['https://api.github.com/repos/jgondellier/jiratools/zipball/v2.0'], $requested);
        self::assertSame('success', $run->getStatus(), $run->getLog());
        self::assertSame('jgondellier-jiratools-9f8e7d6', $run->getVersion());
        self::assertStringEqualsFile($install.'/.env.local', 'DATABASE_URL=mine');
        self::assertFileExists($install.'/src/New.php');
        self::assertFileDoesNotExist($install.'/src/Old.php');
        self::assertStringContainsString('migrations ok in jiratools', $run->getLog());
        self::assertCount(2, $run->getSummary()['commands']);
        self::assertSame(['VERSION', 'src/New.php'], $settings->getDeployedFiles());
        self::assertSame('success', $settings->getLastStatus());
    }

    public function testCommandsOnlyFromScreenStopsOnError(): void
    {
        $this->settings("php -r \"exit(2);\"\nphp -r \"echo 'jamais';\"");

        $crawler = $this->client->request('GET', '/mises-a-jour');
        $form = $crawler->filter('form[action$="/lancer"]')->form();
        $form['deploy']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('code 2', $crawler->filter('#run-log')->text());
        self::assertStringNotContainsString('jamais', $crawler->filter('#run-log')->text());
        $run = $this->em()->getRepository(LocalUpdateRun::class)->findOneBy([]);
        self::assertSame('failed', $run->getStatus());

        $this->client->request('GET', '/mises-a-jour/execution/'.$run->getId().'/journal');
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['finished']);
    }

    public function testTargetIsAlwaysTheInstallationDirectory(): void
    {
        $install = $this->tmp('jiratools');
        mkdir($install, 0777, true);
        $updater = $this->updater($install);

        self::assertSame(str_replace('\\', '/', (string) realpath($install)), $updater->getTargetDirectory());

        $run = $updater->run($this->settings("php -r \"echo getcwd();\""), new UpdateOptions(deploy: false));
        self::assertSame('success', $run->getStatus(), $run->getLog());
        self::assertStringContainsString((string) realpath($install), $run->getLog());
    }

    public function testInterruptedRunIsMarkedFailed(): void
    {
        $updater = $this->updater($this->tmp('jiratools'));
        $run = $updater->prepare($this->settings());
        $run->start();
        $this->em()->flush();

        $updater->markIfInterrupted($run);

        self::assertSame('failed', $run->getStatus());
        self::assertStringContainsString('interrompu', $run->getLog());
    }
}
