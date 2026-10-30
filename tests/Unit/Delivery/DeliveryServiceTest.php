<?php

namespace App\Tests\Unit\Delivery;

use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluencePageInfo;
use App\Delivery\DeliveryCheck;
use App\Delivery\DeliveryScopeResolver;
use App\Delivery\DeliveryService;
use App\Delivery\DeliverySettings;
use App\Delivery\DeliveryTableParser;
use App\Entity\Project;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Repository\DeveloperRepository;
use App\Repository\SettingRepository;
use App\Service\SprintService;
use PHPUnit\Framework\TestCase;

class DeliveryServiceTest extends TestCase
{
    private ?string $lastWsJql = null;

    private const PAGE = '<table><tr><th>Date</th><th>Version</th></tr>'
        .'<tr><td>05/11/2026</td><td>WS-Commande 1.4.2</td></tr>'
        .'<tr><td>18/11/2026</td><td>WS-Commande 1.4.3</td></tr>'
        .'<tr><td>12/12/2026</td><td>WS-Commande 1.5.0</td></tr>'
        .'<tr><td>01/01/2020</td><td>WS-Commande 0.9</td></tr></table>';

    private static function issue(string $key, array $versions = [], array $links = [], string $category = Issue::CATEGORY_IN_PROGRESS, array $labels = []): Issue
    {
        return new Issue(
            $key, 'Résumé '.$key, 'https://jira.test/browse/'.$key, explode('-', $key)[0], 'Statut', $category, 'Story',
            null, null, null, null, null, null, [], $labels, array_keys($versions), [], null, false, null, null, null, null,
            // Version considérée livrée si sa date est passée
            versionDetails: array_map(fn ($name, $date) => ['name' => $name, 'releaseDate' => $date ? new \DateTimeImmutable($date) : null, 'released' => $date && new \DateTimeImmutable($date) < new \DateTimeImmutable('today')], array_keys($versions), $versions),
            links: array_map(fn ($k) => ['key' => $k, 'summary' => '', 'status' => '', 'statusCategory' => 'new', 'projectKey' => explode('-', $k)[0], 'type' => 'dépend de'], $links),
        );
    }

    /** @return array{0: DeliveryService, 1: array} */
    private function service(array $mine, array $wsLinked, array $wsInProgress, int $margin = 0, array $labels = []): DeliveryService
    {
        $settingRepo = $this->createMock(SettingRepository::class);
        $settingRepo->method('getValue')->willReturn([
            'wsProjects' => [['key' => 'WSC', 'prefix' => 'WS-Commande']],
            'wsLabels' => $labels,
            'pageId' => '42',
            'marginDays' => $margin,
        ]);

        $jira = $this->createMock(JiraClient::class);
        $jira->method('searchMany')->willReturnCallback(function (array $searches) use ($mine, $wsLinked, $wsInProgress) {
            if (isset($searches['mine'])) {
                $this->lastWsJql = $searches['ws'][0];

                return ['mine' => $mine, 'ws' => $wsInProgress];
            }
            // Tickets demandés par clé
            preg_match('/key in \(([^)]*)\)/', $searches['c0'][0], $m);
            $keys = array_map('trim', explode(',', $m[1]));

            return ['c0' => array_values(array_filter($wsLinked, fn (Issue $i) => \in_array($i->key, $keys, true)))];
        });

        $confluence = $this->createMock(ConfluenceClient::class);
        $confluence->method('getPageContent')->willReturn([
            'info' => new ConfluencePageInfo('42', 'Calendrier', 'https://wiki/42', 'WS', 3, null, null),
            'storage' => self::PAGE,
        ]);

        $sprints = $this->createMock(SprintService::class);
        $sprints->method('getActiveSprints')->willReturn([]);
        $developers = $this->createMock(DeveloperRepository::class);
        $developers->method('findBy')->willReturn([]);

        $settings = new DeliverySettings($settingRepo);

        return new DeliveryService($settings, $jira, $confluence, $sprints, $developers, new DeliveryTableParser(), new DeliveryScopeResolver($jira, $settings));
    }

    private static function messages(DeliveryCheck $check): string
    {
        return implode(' | ', array_column($check->problems, 'message'));
    }

    public function testRules(): void
    {
        $mine = [
            self::issue('APP-1', ['1.0' => '2026-11-20'], ['WSC-1', 'WSC-1']),  // lien en double
            self::issue('APP-2', ['1.0' => '2026-11-20'], ['WSC-2']),
            self::issue('APP-3', ['1.0' => '2026-11-20'], ['WSC-3']),
            self::issue('APP-4', ['2.0' => null], ['WSC-4']),
            self::issue('APP-5', [], ['WSC-1', 'OTHER-1']),
            self::issue('APP-6', ['1.0' => '2026-11-20'], ['WSC-6']),
        ];
        $wsLinked = [
            self::issue('WSC-1', ['1.4.2' => '2026-11-05'], [], Issue::CATEGORY_DONE),
            self::issue('WSC-2', ['1.5.0' => '2026-12-10']),
            self::issue('WSC-3', []),
            self::issue('WSC-4', ['9.9' => '2026-12-01']),
            self::issue('WSC-6', ['0.9' => '2020-01-01']),
        ];
        $wsInProgress = [
            self::issue('WSC-2', ['1.5.0' => '2026-12-10'], ['APP-2']),
            self::issue('WSC-7', [], ['OTHER-9']),
        ];

        $report = $this->service($mine, $wsLinked, $wsInProgress)->getReport([(new Project())->setJiraProjectKey('APP')]);
        $checks = [];
        foreach ($report['checks'] as $check) {
            $checks[$check->issue->key] = $check;
        }

        self::assertCount(6, $report['checks'], 'un seul contrôle par couple, liens hors projets WS ignorés');

        self::assertSame(DeliveryCheck::OK, $checks['APP-1']->getLevel());
        self::assertSame(15, $checks['APP-1']->getMarginDays());

        self::assertSame(DeliveryCheck::ERROR, $checks['APP-2']->getLevel());
        self::assertStringContainsString('APRÈS ma version', self::messages($checks['APP-2']));
        self::assertStringContainsString('date Jira 10/12/2026 ≠ date Confluence 12/12/2026', self::messages($checks['APP-2']));
        self::assertSame('2026-12-12', $checks['APP-2']->wsDate->format('Y-m-d'), 'la date Confluence fait foi');

        self::assertSame(DeliveryCheck::ERROR, $checks['APP-3']->getLevel());
        self::assertStringContainsString('pas de version', self::messages($checks['APP-3']));

        self::assertSame(DeliveryCheck::WARNING, $checks['APP-4']->getLevel());
        self::assertStringContainsString('« 2.0 » n\'a pas de date', self::messages($checks['APP-4']));
        self::assertStringContainsString('« 9.9 » absente du calendrier', self::messages($checks['APP-4']));
        self::assertSame(['2.0'], $report['myVersionsWithoutDate']);

        self::assertSame(DeliveryCheck::WARNING, $checks['APP-5']->getLevel());
        self::assertStringContainsString('pas de version', self::messages($checks['APP-5']));

        self::assertStringContainsString('ticket WS n\'est pas terminé', self::messages($checks['APP-6']), 'livraison passée, ticket WS en cours');

        self::assertSame(['WSC-7'], array_map(fn (Issue $i) => $i->key, $report['unlinked']));
    }

    public function testMargin(): void
    {
        $mine = [self::issue('APP-1', ['1.0' => '2026-11-20'], ['WSC-1'])];
        $ws = [self::issue('WSC-1', ['1.4.3' => '2026-11-18'], [], Issue::CATEGORY_DONE)];

        $ok = $this->service($mine, $ws, [], 2)->getReport([(new Project())->setJiraProjectKey('APP')])['checks'][0];
        self::assertSame(DeliveryCheck::OK, $ok->getLevel(), '2 jours avant, marge de 2 jours : OK');

        $ko = $this->service($mine, $ws, [], 3)->getReport([(new Project())->setJiraProjectKey('APP')])['checks'][0];
        self::assertSame(DeliveryCheck::ERROR, $ko->getLevel());
        self::assertStringContainsString('moins de 3 jour(s) avant', self::messages($ko));
    }

    public function testWsLabel(): void
    {
        $mine = [
            self::issue('APP-1', ['1.0' => '2026-11-20'], ['WSC-1']),
            self::issue('APP-2', ['1.0' => '2026-11-20'], ['WSC-2']),
        ];
        $labelled = [
            // Étiqueté et lié
            self::issue('WSC-1', ['1.4.2' => '2026-11-05'], ['APP-1'], Issue::CATEGORY_DONE, ['WS-Equipe']),
            // Étiqueté, non lié à mes projets
            self::issue('WSC-8', ['1.5.0' => '2026-12-10'], ['OTHER-1'], Issue::CATEGORY_IN_PROGRESS, ['ws-equipe']),
            // Étiqueté, non lié, mais terminé : pas d'alerte
            self::issue('WSC-9', [], [], Issue::CATEGORY_DONE, ['ws-equipe']),
        ];
        // Lié mais sans l'étiquette : lu à part, contrôlé et signalé
        $linkedOnly = [self::issue('WSC-2', ['0.9' => '2020-01-01'], ['APP-2'], Issue::CATEGORY_DONE, ['autre'])];

        $report = $this->service($mine, $linkedOnly, $labelled, 0, ['ws-equipe'])->getReport([(new Project())->setJiraProjectKey('APP')]);

        self::assertStringContainsString('labels in ("ws-equipe")', $this->lastWsJql);
        self::assertStringContainsString('project in ("WSC")', $this->lastWsJql);

        $checks = [];
        foreach ($report['checks'] as $check) {
            $checks[$check->issue->key] = $check;
        }
        self::assertSame(DeliveryCheck::OK, $checks['APP-1']->getLevel(), 'étiquette reconnue sans tenir compte de la casse');
        self::assertStringContainsString('n\'a pas l\'étiquette ws-equipe (il n\'apparaît pas sur le board)', self::messages($checks['APP-2']));

        self::assertSame(['WSC-8'], array_map(fn (Issue $i) => $i->key, $report['unlinked']));

        // Versions : uniquement celles des tickets WS liés à mes tickets (WSC-8, non lié, exclu ;
        // 0.9 livrée il y a plus de 90 jours exclue)
        self::assertSame(['1.4.2'], array_column($report['versions'], 'name'));
        self::assertSame(['WSC-1'], $report['versions'][0]['issues']);
    }
}
