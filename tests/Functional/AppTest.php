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

    public function testRedirectAfterSaveStaysInTheApplication(): void
    {
        foreach (['/\\attaquant.example' => '/parametrage/equipes', '//attaquant.example' => '/parametrage/equipes', '/projets' => '/projets'] as $redirect => $expected) {
            $crawler = $this->client->request('GET', '/parametrage/equipes/nouvelle?redirect='.rawurlencode($redirect));
            $this->client->submit($crawler->selectButton('Enregistrer')->form(['team[name]' => 'Équipe '.md5($redirect), 'team[wipLimit]' => 3]));
            self::assertResponseRedirects($expected);
        }
    }

    public function testSecurityHeadersAndDesignSystemLayout(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString("frame-ancestors 'none'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertCount(1, $crawler->filter('header.o-header a.a-skipLink[href="#contenu"]'));
        self::assertCount(1, $crawler->filter('main#contenu'));
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('footer.o-footer[role="contentinfo"]'));
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

        // Par défaut seul "En cours" compte : le ticket en Code Review d'Alice (développeuse) passe en "autres tickets"
        $this->saveWorkStatuses(['default' => 'En cours']);
        $alice = $this->memberCard('Alice Martin');
        self::assertStringContainsString('idle', $alice->attr('class'), 'Alice n\'a aucun ticket en développement');
        self::assertStringContainsString('APP-1', $alice->filter('.other-work')->text());
        self::assertStringContainsString('Aucun ticket en dev', $alice->text());

        // Pour une testeuse dont le rôle QA compte "Code Review", le même ticket est son travail
        $this->em->getRepository(Developer::class)->findOneBy(['jiraAccountId' => 'acc-alice'])->setRole('qa');
        $this->em->flush();
        $this->saveWorkStatuses(['default' => 'En cours', 'qa' => 'Code Review']);
        $alice = $this->memberCard('Alice Martin');
        self::assertStringNotContainsString('idle', $alice->attr('class'));
        self::assertStringContainsString('1 en test', $alice->text());
        self::assertCount(0, $alice->filter('.other-work'));

        foreach (['/sprints', '/sprints/1', '/backlog/1', '/suivi', '/projets/1', '/sprints/1?sprint=', '/?team=', '/suivi?project=', '/parametrage/connexion', '/parametrage/membres/import?project=APP'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testDeliveriesSettingsAndPage(): void
    {
        $this->em->persist((new Project())->setName('Portail')->setJiraProjectKey('APP')->setComponents(['Portail']));
        $this->em->flush();

        // Non paramétré : redirection vers le paramétrage
        $this->client->request('GET', '/livraisons');
        self::assertResponseRedirects('/parametrage/livraisons');

        $crawler = $this->client->request('GET', '/parametrage/livraisons');
        $this->client->submit($crawler->selectButton('Enregistrer et tester la lecture')->form([
            'wsProjects' => "wsc = WS-Commande\ninvalide!",
            'page' => 'https://jira.test/wiki/spaces/WS/pages/777/Calendrier',
            'marginDays' => '2',
        ]));
        self::assertResponseRedirects('/parametrage/livraisons');
        $this->client->followRedirect();
        self::assertSelectorTextContains('textarea[name=wsProjects]', 'WSC = WS-Commande');
        self::assertInputValueSame('page', '777');
        self::assertSelectorTextContains('table', 'WS-Commande 1.4.2');
        self::assertSelectorTextContains('table', '05/11/2026');

        $this->client->request('GET', '/livraisons');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dépendances livraison');
        self::assertSelectorTextContains('body', 'Calendrier des livraisons');
    }

    public function testReleaseContentFromGitLab(): void
    {
        $this->em->persist((new Project())->setName('Portail')->setJiraProjectKey('APP')->setComponents(['Portail']));
        $this->em->flush();

        // Non paramétré : redirection vers le paramétrage
        $this->client->request('GET', '/livraisons/contenu');
        self::assertResponseRedirects('/parametrage/contenu-mep');

        $crawler = $this->client->request('GET', '/parametrage/contenu-mep');
        $this->client->submit($crawler->selectButton('Enregistrer et tester la lecture')->form([
            'repositories' => 'https://gitlab.test/groupe/portail.git',
            'branch' => 'develop',
            'reference' => 'master',
        ]));
        self::assertResponseRedirects('/parametrage/contenu-mep');
        $this->client->followRedirect();
        self::assertSelectorTextContains('textarea[name=repositories]', 'groupe/portail');
        self::assertSelectorTextContains('body', '4 commit(s) dans develop absents de master, 1 merge request(s), 2 commit(s) hors merge request, 2 ticket(s) cité(s) : APP-1, APP-404');

        // Version de la MEP par défaut : la prochaine version non livrée (1.4.2)
        $crawler = $this->client->request('GET', '/livraisons/contenu');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Contenu de la MEP');
        $rows = $crawler->filter('table.delivery-table tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('APP-404', $rows->eq(0)->text());
        self::assertStringContainsString('Introuvable dans Jira', $rows->eq(0)->text());
        self::assertStringContainsString('APP-1', $rows->eq(1)->text());
        self::assertStringContainsString('À jour', $rows->eq(1)->text());
        self::assertStringContainsString('!12 Export CSV', $rows->eq(1)->text());
        self::assertStringNotContainsString('APP-50', $crawler->filter('body')->text(), 'MR déjà en production');
        self::assertSelectorTextContains('body', 'APP-9');
        self::assertSelectorTextContains('body', 'Hotfix libellé');

        // Aucune version choisie : pas de contrôle de version ni de tickets "sans code"
        $crawler = $this->client->request('GET', '/livraisons/contenu?v=1&problems=1');
        self::assertCount(1, $crawler->filter('table.delivery-table tbody tr'));
        self::assertSelectorTextNotContains('body', 'APP-9');

        $this->client->request('GET', '/parametrage/connexion');
        self::assertSelectorTextContains('body', 'Alice Martin (amartin)');
    }

    /**
     * Coche les statuts donnés (colonne => statut) dans le paramétrage des statuts de travail.
     */
    private function saveWorkStatuses(array $checks): void
    {
        $crawler = $this->client->request('GET', '/parametrage/statuts-de-travail');
        $form = $crawler->selectButton('Enregistrer')->form();
        foreach ($form->all() as $name => $field) {
            if ($field instanceof \Symfony\Component\DomCrawler\Field\ChoiceFormField && 'checkbox' === $field->getType()) {
                preg_match('/^work\[(\w+)\]/', $name, $m);
                ($checks[$m[1]] ?? null) === $field->availableOptionValues()[0] ? $field->tick() : $field->untick();
            }
        }
        $this->client->submit($form);
        self::assertResponseRedirects('/parametrage/statuts-de-travail');
    }

    private function memberCard(string $name): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/');
        $card = $crawler->filter('.member-card')->reduce(fn ($c) => str_contains($c->text(), $name));
        self::assertCount(1, $card);

        return $card;
    }
}
