<?php

namespace App\Tests\Unit\Delivery;

use App\Delivery\DeliveryCalendar;
use App\Delivery\DeliveryScopeResolver;
use App\Delivery\DeliverySettings;
use App\Delivery\DeliveryTableParser;
use App\Delivery\ReleaseReadiness;
use App\Delivery\ReleaseReadinessService;
use App\Entity\Project;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Repository\SettingRepository;
use App\Service\IssueView;
use App\Service\IssueViewFactory;
use App\Service\StatusAge;
use App\Service\WorkingTime;
use PHPUnit\Framework\TestCase;

class ReleaseReadinessServiceTest extends TestCase
{
    private array $searches = [];

    private static function day(int $offset): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('today %+d days', $offset));
    }

    /** Versions connues : nom => [identifiant, décalage en jours de la date Jira (null = sans date), livrée] */
    private static array $versions = [];

    private static function issue(string $key, string $version, string $category, array $o = []): Issue
    {
        [$id, $offset, $released] = self::$versions[$version] ?? ['0', null, false];
        return new Issue(
            $key, 'Résumé', 'u', explode('-', $key)[0], $o['status'] ?? 'Statut', $category, 'Story', null, null, null,
            array_key_exists('assignee', $o) ? $o['assignee'] : 'acc', 'Alice', null, [], $o['labels'] ?? [], [$version], [], $o['points'] ?? null, $o['flagged'] ?? false,
            null, null, null, null,
            versionDetails: [['id' => $id, 'name' => $version, 'releaseDate' => null === $offset ? null : self::day($offset), 'released' => $released]],
            links: array_map(fn ($k) => ['key' => $k, 'summary' => '', 'status' => '', 'statusCategory' => 'new', 'projectKey' => explode('-', $k)[0], 'type' => 'dépend de'], $o['links'] ?? []),
        );
    }

    /**
     * @return array<string, ReleaseReadiness> par nom de version
     */
    private function evaluate(array $issues, string $calendarHtml = '', array $ages = []): array
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getValue')->willReturn([
            'wsProjects' => [['key' => 'WSC', 'prefix' => 'WS']],
            'wsLabels' => ['ws-equipe'],
            'horizonDays' => 30,
            'startDays' => 5,
        ]);

        $jira = $this->createMock(JiraClient::class);
        $jira->method('versionUrl')->willReturn('https://jira/v');
        $jira->method('searchMany')->willReturnCallback(function (array $searches) use ($issues) {
            $this->searches += $searches;
            $result = [];
            foreach ($searches as $key => [$jql]) {
                $ws = !str_starts_with($key, 'mine');
                if ($ws) {
                    preg_match('/key in \(([^)]*)\)/', $jql, $m);
                    $keys = array_map('trim', explode(',', $m[1]));
                }
                $result[$key] = array_values(array_filter($issues, fn (Issue $i) => ($ws ? \in_array($i->key, $keys, true) : 'APP' === $i->projectKey)
                    && $i->isDone() === str_ends_with($key, '_done')));
            }

            return $result;
        });

        $views = $this->createMock(IssueViewFactory::class);
        $views->method('create')->willReturnCallback(fn (Issue $i) => new IssueView($i, $ages[$i->key] ?? new StatusAge(0.5, StatusAge::OK, 3, 5), null));

        $calendar = new DeliveryCalendar((new DeliveryTableParser())->parse($calendarHtml ?: '<table><tr><th>Date</th><th>Version</th></tr></table>')['rows']);
        $settings = new DeliverySettings($repo);
        $service = new ReleaseReadinessService($settings, new DeliveryScopeResolver($jira, $settings), $jira, $views, new WorkingTime(false));
        $releases = $service->getUpcoming([(new Project())->setJiraProjectKey('APP')], $calendar);

        $byName = [];
        foreach ($releases as $release) {
            $byName[$release->projectKey.' '.$release->name] = $release;
        }

        return $byName;
    }

    private static function messages(ReleaseReadiness $r, ?string $key = null): string
    {
        return implode(' | ', array_column(null === $key ? $r->alerts : ($r->issueAlerts[$key] ?? []), 'message'));
    }

    public function testOnlyVersionsImpactedByMyTicketsAndLinkedWs(): void
    {
        self::$versions = [
            '1.0' => ['1', 10, false],
            '1.1' => ['2', 60, false],        // hors horizon
            '2.0' => ['4', null, false],      // sans date
            '1.2' => ['5', 50, false],        // hors horizon dans Jira, mais dans 20 j dans Confluence
            '1.4.2 WS' => ['6', -3, false],   // en retard
            '9.9 WS' => ['7', 5, false],      // version WS d'un ticket non lié à mes tickets
            '3.0' => ['8', null, false],      // version sans ticket de mon projet
        ];
        $date = self::day(20)->format('d/m/Y');
        $calendar = "<table><tr><th>Date</th><th>Version</th></tr><tr><td>$date</td><td>1.2</td></tr></table>";
        $issues = [
            self::issue('APP-1', '1.0', Issue::CATEGORY_IN_PROGRESS, ['links' => ['WSC-1']]),
            self::issue('APP-2', '1.1', Issue::CATEGORY_TODO),
            self::issue('APP-3', '2.0', Issue::CATEGORY_TODO),
            self::issue('APP-4', '1.2', Issue::CATEGORY_DONE),
            self::issue('WSC-1', '1.4.2 WS', Issue::CATEGORY_IN_PROGRESS, ['labels' => ['ws-equipe']]),
            self::issue('WSC-2', '9.9 WS', Issue::CATEGORY_IN_PROGRESS, ['labels' => ['ws-equipe']]),
        ];

        $releases = $this->evaluate($issues, $calendar);

        self::assertSame(['WSC 1.4.2 WS', 'APP 1.0', 'APP 1.2', 'APP 2.0'], array_keys($releases), 'triées par date, sans date à la fin');
        self::assertSame(['WSC-1'], array_keys($releases['WSC 1.4.2 WS']->issues), 'seuls les tickets WS liés à mes tickets');
        self::assertSame(self::day(20)->format('Y-m-d'), $releases['APP 1.2']->getDate()->format('Y-m-d'), 'la date Confluence fait foi');
        self::assertStringContainsString('ligne 1', $releases['APP 1.2']->getDateSourceLabel());
        self::assertStringContainsString('≠ date Confluence', self::messages($releases['APP 1.2']));
        self::assertStringContainsString('Absente du calendrier', self::messages($releases['APP 1.0']));
        self::assertStringContainsString('Date de la version dans Jira', $releases['APP 1.0']->getDateSourceLabel());
        self::assertStringContainsString('Pas de date de livraison', self::messages($releases['APP 2.0']));
        self::assertSame('Aucune date', $releases['APP 2.0']->getDateSourceLabel());
        self::assertNull($releases['APP 2.0']->getDate(), 'version sans date : aucune date inventée');
        self::assertSame(ReleaseReadiness::ERROR, $releases['WSC 1.4.2 WS']->getLevel());
        self::assertSame(11, $releases['APP 1.0']->daysLeft);

        self::assertStringContainsString('fixVersion in unreleasedVersions() AND ((project = "APP"))', $this->searches['mine_open'][0]);
        self::assertStringStartsWith('key in (WSC-1)', $this->searches['ws0_open'][0]);
    }

    public function testDateNotTakenFromAnotherComponentOrComment(): void
    {
        self::$versions = ['4.68.0 WS' => ['10', null, false], '1.6.0' => ['11', null, false]];
        $date = self::day(5)->format('d/m/Y');
        // La page cite « 4.68.0 » sans composant dans un commentaire et « 1.6.0 BDD » : rien ne concerne mes versions
        $calendar = "<table><tr><th>Date</th><th>Version</th></tr><tr><td>$date</td><td>1.6.0 BDD (suite au correctif 4.68.0)</td></tr></table>";
        $issues = [
            self::issue('APP-1', '1.6.0', Issue::CATEGORY_TODO, ['links' => ['WSC-1']]),
            self::issue('WSC-1', '4.68.0 WS', Issue::CATEGORY_TODO),
        ];

        $releases = $this->evaluate($issues, $calendar);

        self::assertNull($releases['APP 1.6.0']->getDate());
        self::assertNull($releases['WSC 4.68.0 WS']->getDate());
        self::assertStringContainsString('numéro trouvé pour BDD', self::messages($releases['APP 1.6.0']));
    }

    public function testLinkPointingToAnotherVersionIsReported(): void
    {
        self::$versions = ['4.70.0 WS' => ['777', null, false], '1.0' => ['1', 10, false]];
        $date = self::day(5)->format('d/m/Y');
        $calendar = "<table><tr><th>Date</th><th>Version</th></tr><tr><td>$date</td><td><a href=\"https://jira.test/projects/WSC/versions/777\">4.69.0 WS</a></td></tr></table>";
        $issues = [
            self::issue('APP-1', '1.0', Issue::CATEGORY_TODO, ['links' => ['WSC-1']]),
            self::issue('WSC-1', '4.70.0 WS', Issue::CATEGORY_TODO),
        ];

        $release = $this->evaluate($issues, $calendar)['WSC 4.70.0 WS'];

        self::assertSame(self::day(5)->format('Y-m-d'), $release->getDate()->format('Y-m-d'));
        self::assertStringContainsString('le lien « 4.69.0 WS » pointe vers cette version Jira « 4.70.0 WS »', self::messages($release));
        self::assertStringContainsString('lien vers la version Jira « 4.69.0 WS »', $release->getDateSourceLabel());
    }

    public function testIssueRules(): void
    {
        self::$versions = ['1.0' => ['1', 3, false], '1.1' => ['2', 8, false], '1.4.2' => ['3', 6, false]];
        $issues = [
            self::issue('APP-1', '1.0', Issue::CATEGORY_TODO),                                   // pas commencé à J-4
            self::issue('APP-2', '1.1', Issue::CATEGORY_TODO),                                   // pas commencé à J-9 : à surveiller
            self::issue('APP-3', '1.0', Issue::CATEGORY_IN_PROGRESS, ['flagged' => true, 'assignee' => null]),
            self::issue('APP-4', '1.0', Issue::CATEGORY_IN_PROGRESS, ['status' => 'Code Review']),
            self::issue('APP-5', '1.0', Issue::CATEGORY_IN_PROGRESS, ['links' => ['WSC-1']]),   // dépend d'un WS livré après
            self::issue('APP-6', '1.0', Issue::CATEGORY_DONE, ['points' => 3]),
            self::issue('WSC-1', '1.4.2', Issue::CATEGORY_IN_PROGRESS, ['labels' => ['ws-equipe']]),
        ];
        $ages = ['APP-4' => new StatusAge(4.2, StatusAge::CRITICAL, 1, 2)];

        $releases = $this->evaluate($issues, '', $ages);
        $v10 = $releases['APP 1.0'];

        self::assertStringContainsString('Pas commencé à 4 jour(s)', self::messages($v10, 'APP-1'));
        self::assertSame(ReleaseReadiness::WARNING, $releases['APP 1.1']->getIssueLevel('APP-2'));
        self::assertStringContainsString('Pas encore commencé', self::messages($releases['APP 1.1'], 'APP-2'));
        self::assertStringContainsString('bloqué', self::messages($v10, 'APP-3'));
        self::assertStringContainsString('Personne n\'est assigné', self::messages($v10, 'APP-3'));
        self::assertStringContainsString('Dans « Code Review » depuis 4,2 j', self::messages($v10, 'APP-4'));
        self::assertStringContainsString('Lié à WSC-1, livré en 1.4.2', self::messages($v10, 'APP-5'));
        self::assertSame(ReleaseReadiness::OK, $v10->getIssueLevel('APP-6'), 'terminé : pas d\'alerte');
        self::assertSame(ReleaseReadiness::ERROR, $v10->getLevel());
        self::assertSame(4, $v10->countIssuesInAlert(ReleaseReadiness::ERROR));
        self::assertSame(['new' => 1, 'indeterminate' => 3, 'done' => 1], $v10->getCounts());
        self::assertSame(100, $v10->getProgress(), 'seul le ticket terminé est estimé');

        self::assertTrue($this->searches['mine_open'][2], 'historique demandé pour les tickets non terminés');
        self::assertFalse($this->searches['mine_done'][2]);
    }

    public function testOverdueVersionFlagsOpenIssues(): void
    {
        self::$versions = ['1.0' => ['1', -2, false]];
        $releases = $this->evaluate([self::issue('APP-1', '1.0', Issue::CATEGORY_IN_PROGRESS), self::issue('APP-2', '1.0', Issue::CATEGORY_DONE)]);

        self::assertStringContainsString('date de livraison est passée', self::messages($releases['APP 1.0'], 'APP-1'));
        self::assertArrayNotHasKey('APP-2', $releases['APP 1.0']->issueAlerts);
        self::assertTrue($releases['APP 1.0']->isOverdue());
    }
}
