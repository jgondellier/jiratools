<?php

namespace App\Tests\Functional;

use App\Entity\Developer;
use App\Entity\Project;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AppTest extends WebTestCase
{
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

    public function testWelcomePageWhenNothingConfigured(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue');
    }

    public function testCreateTeamThroughForm(): void
    {
        $crawler = $this->client->request('GET', '/parametrage/equipes/nouvelle');
        $form = $crawler->selectButton('Enregistrer')->form([
            'team[name]' => 'Équipe Alpha',
            'team[color]' => '#123456',
            'team[wipLimit]' => 3,
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/parametrage/equipes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('table', 'Équipe Alpha');
    }

    public function testStatusRuleValidation(): void
    {
        $crawler = $this->client->request('GET', '/parametrage/seuils-statuts/nouveau');
        $form = $crawler->selectButton('Enregistrer')->form([
            'status_rule[statusName]' => 'En test',
            'status_rule[warningDays]' => '3',
            'status_rule[criticalDays]' => '1',
        ]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'supérieur ou égal');
    }

    public function testDashboardShowsCurrentTicketAndIdleMember(): void
    {
        $team = (new Team())->setName('Alpha');
        $alice = (new Developer())->setDisplayName('Alice Martin')->setJiraAccountId('acc-alice')->setTeam($team);
        $bob = (new Developer())->setDisplayName('Bob Petit')->setJiraAccountId('acc-bob')->setTeam($team);
        $project = (new Project())->setName('Portail')->setJiraProjectKey('APP')->setComponents(['Portail'])->setJiraBoardId(42)->addTeam($team);
        foreach ([$team, $alice, $bob, $project] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('APP-1', $html);
        self::assertStringContainsString('Ticket qui traîne', $html);
        self::assertStringContainsString('level-critical', $html, 'Ticket depuis 10 jours en "En cours" => alerte rouge');
        self::assertSelectorTextContains('.member-card.idle', 'Bob Petit');

        foreach (['/sprints', '/sprints/1', '/backlog/1', '/suivi', '/projets/1', '/release-note?project=1&version=2.0', '/parametrage/connexion', '/parametrage/membres/import?project=APP'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }
}
