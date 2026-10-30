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

    /** Versions connues : nom => [identifiant, décalage en jours de la date Jira (null = sans date), livrée] */
    private static array $versions = [];

    private static function day(int $offset): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('today %+d days', $offset));
    }

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
     * @return array<string, ReleaseReadiness> par "PROJET version"
     */
    private function evaluate(array $issues, string $calendarHtml = '', array $ages = [], int $margin = 0): array
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getValue')->willReturn([
            'wsProjects' => [['key' => 'WSC', 'prefix' => null]],
            'wsLabels' => ['ws-equipe'],
            'horizonDays' => 30,
            'startDays' => 5,
            'marginDays' => $margin,
        ]);

        $jira = $this->createMock(JiraClient::class);
        $jira->method('versionUrl')->willReturn('https://jira/v');
        $jira->method('searchMany')->willReturnCallback(function (array $searches) use ($issues) {
            $this->searches += $searches;
            $result = [];
            foreach ($searches as $key => [$jql]) {
                $ws = !str_starts_with($key, 'mine');
                $keys = [];
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

        $byName = [];
        foreach ($service->getUpcoming([(new Project())->setJiraProjectKey('APP')], $calendar) as $release) {
            $byName[$release->projectKey.' '.$release->name] = $release;
        }

        return $byName;
    }

    private static function messages(ReleaseReadiness $r, ?string $key = null): string
    {
        return implode(' | ', array_column(null === $key ? $r->alerts : ($r->issueAlerts[$key] ?? []), 'message'));
    }

    private static function calendar(array $rows): string
    {
        $html = '<table><tr><th>Date</th><th>Version</th></tr>';
        foreach ($rows as $version => $offset) {
            $html .= sprintf('<tr><td>%s</td><td>%s</td></tr>', self::day($offset)->format('d/m/Y'), $version);
        }

        return $html.'</table>';
    }

    public function testOnlyWsVersionsOfTicketsLinkedToMine(): void
    {
        self::$versions = [
            '1.0' => ['1', 10, false],          // ma version : jamais affichée
            '4.1.0 WS' => ['10', 8, false],
            '4.2.0 WS' => ['11', 60, false],    // hors horizon
            '4.3.0 WS' => ['12', null, false],  // sans date dans Jira : date Confluence
            '4.4.0 WS' => ['13', null, false],  // sans date nulle part
            '9.9.0 WS' => ['14', 5, false],     // ticket WS non lié à mes tickets
        ];
        $issues = [
            self::issue('APP-1', '1.0', Issue::CATEGORY_IN_PROGRESS, ['links' => ['WSC-1', 'WSC-2', 'WSC-3', 'WSC-4']]),
            self::issue('WSC-1', '4.1.0 WS', Issue::CATEGORY_IN_PROGRESS),
            self::issue('WSC-2', '4.2.0 WS', Issue::CATEGORY_TODO),
            self::issue('WSC-3', '4.3.0 WS', Issue::CATEGORY_TODO),
            self::issue('WSC-4', '4.4.0 WS', Issue::CATEGORY_TODO),
            self::issue('WSC-9', '9.9.0 WS', Issue::CATEGORY_TODO),
        ];

        $releases = $this->evaluate($issues, self::calendar(['4.3.0 WS' => 4]));

        self::assertSame(['WSC 4.3.0 WS', 'WSC 4.1.0 WS', 'WSC 4.4.0 WS'], array_keys($releases), 'versions WS seulement, par date, sans date à la fin');
        self::assertSame(['WSC-1'], array_keys($releases['WSC 4.1.0 WS']->issues));
        self::assertSame('APP-1', $releases['WSC 4.1.0 WS']->requiredBy['WSC-1'][0]['issue']->key, 'ticket de mon projet qui en dépend');
        self::assertSame('1.0', $releases['WSC 4.1.0 WS']->requiredBy['WSC-1'][0]['version']);
        self::assertSame(self::day(4)->format('Y-m-d'), $releases['WSC 4.3.0 WS']->getDate()->format('Y-m-d'), 'pas de date Jira : date Confluence');
        self::assertStringContainsString('Pas de date dans Jira — Confluence ligne 1', $releases['WSC 4.3.0 WS']->getDateSourceLabel());
        self::assertNull($releases['WSC 4.4.0 WS']->getDate());
        self::assertStringContainsString('Pas de date de livraison', self::messages($releases['WSC 4.4.0 WS']));

        self::assertStringContainsString('fixVersion in unreleasedVersions() AND ((project = "APP"))', $this->searches['mine_open'][0]);
        self::assertStringStartsWith('key in (WSC-1, WSC-2, WSC-3, WSC-4)', $this->searches['ws0_open'][0]);
    }

    public function testJiraDateHasPriorityAndGapIsHighlighted(): void
    {
        self::$versions = ['1.0' => ['1', 20, false], '4.1.0 WS' => ['10', 8, false], '4.2.0 WS' => ['11', 9, false]];
        $issues = [
            self::issue('APP-1', '1.0', Issue::CATEGORY_IN_PROGRESS, ['links' => ['WSC-1', 'WSC-2']]),
            self::issue('WSC-1', '4.1.0 WS', Issue::CATEGORY_IN_PROGRESS),
            self::issue('WSC-2', '4.2.0 WS', Issue::CATEGORY_IN_PROGRESS),
        ];

        // Confluence : 4.1.0 WS à J+3 (écart avec Jira J+8), 4.2.0 WS identique
        $releases = $this->evaluate($issues, self::calendar(['4.1.0 WS' => 3, '4.2.0 WS' => 9]));

        $gap = $releases['WSC 4.1.0 WS'];
        self::assertSame(self::day(8)->format('Y-m-d'), $gap->getDate()->format('Y-m-d'), 'date Jira prioritaire');
        self::assertTrue($gap->hasDateGap());
        self::assertSame(ReleaseReadiness::ERROR, $gap->getLevel());
        self::assertStringContainsString('Écart de date : Jira '.self::day(8)->format('d/m/Y').' ≠ Confluence '.self::day(3)->format('d/m/Y'), self::messages($gap));
        self::assertStringStartsWith('Date de publication Jira — Confluence ligne 1', $gap->getDateSourceLabel());

        self::assertFalse($releases['WSC 4.2.0 WS']->hasDateGap());
        self::assertSame(ReleaseReadiness::OK, $releases['WSC 4.2.0 WS']->getLevel());
    }

    public function testWsDeliveredTooLateForMyVersion(): void
    {
        self::$versions = ['1.0' => ['1', 10, false], '4.1.0 WS' => ['10', 12, false], '4.2.0 WS' => ['11', 9, false]];
        $issues = [
            self::issue('APP-1', '1.0', Issue::CATEGORY_IN_PROGRESS, ['links' => ['WSC-1', 'WSC-2']]),
            self::issue('WSC-1', '4.1.0 WS', Issue::CATEGORY_IN_PROGRESS),
            self::issue('WSC-2', '4.2.0 WS', Issue::CATEGORY_IN_PROGRESS),
        ];

        $releases = $this->evaluate($issues, '', [], 2);

        self::assertStringContainsString('APP-1 (version 1.0 du '.self::day(10)->format('d/m/Y').') en dépend', self::messages($releases['WSC 4.1.0 WS'], 'WSC-1'));
        self::assertStringContainsString('APRÈS cette version', self::messages($releases['WSC 4.1.0 WS'], 'WSC-1'));
        self::assertStringContainsString('moins de 2 jour(s) avant', self::messages($releases['WSC 4.2.0 WS'], 'WSC-2'), 'marge non respectée');
    }

    public function testIssueRules(): void
    {
        self::$versions = ['1.0' => ['1', 20, false], '4.1.0 WS' => ['10', 3, false], '4.2.0 WS' => ['11', 8, false]];
        $issues = [
            self::issue('APP-1', '1.0', Issue::CATEGORY_IN_PROGRESS, ['links' => ['WSC-1', 'WSC-2', 'WSC-3', 'WSC-4', 'WSC-5']]),
            self::issue('WSC-1', '4.1.0 WS', Issue::CATEGORY_TODO),                                                   // pas commencé à J-4
            self::issue('WSC-2', '4.2.0 WS', Issue::CATEGORY_TODO),                                                   // pas commencé à J-9
            self::issue('WSC-3', '4.1.0 WS', Issue::CATEGORY_IN_PROGRESS, ['flagged' => true, 'assignee' => null]),
            self::issue('WSC-4', '4.1.0 WS', Issue::CATEGORY_IN_PROGRESS, ['status' => 'Code Review']),
            self::issue('WSC-5', '4.1.0 WS', Issue::CATEGORY_DONE, ['points' => 3]),
        ];
        $ages = ['WSC-4' => new StatusAge(4.2, StatusAge::CRITICAL, 1, 2)];

        $releases = $this->evaluate($issues, '', $ages);
        $v = $releases['WSC 4.1.0 WS'];

        self::assertStringContainsString('Pas commencé à 4 jour(s)', self::messages($v, 'WSC-1'));
        self::assertSame(ReleaseReadiness::WARNING, $releases['WSC 4.2.0 WS']->getIssueLevel('WSC-2'));
        self::assertStringContainsString('bloqué', self::messages($v, 'WSC-3'));
        self::assertStringContainsString('Personne n\'est assigné', self::messages($v, 'WSC-3'));
        self::assertStringContainsString('Dans « Code Review » depuis 4,2 j', self::messages($v, 'WSC-4'));
        self::assertSame(ReleaseReadiness::OK, $v->getIssueLevel('WSC-5'));
        self::assertSame(3, $v->countIssuesInAlert(ReleaseReadiness::ERROR));
        self::assertSame(['new' => 1, 'indeterminate' => 2, 'done' => 1], $v->getCounts());
        self::assertTrue($this->searches['mine_open'][2], 'historique demandé pour les tickets non terminés');
        self::assertFalse($this->searches['mine_done'][2]);
    }

    public function testOverdueVersionFlagsOpenIssues(): void
    {
        self::$versions = ['1.0' => ['1', 10, false], '4.1.0 WS' => ['10', -2, false]];
        $releases = $this->evaluate([
            self::issue('APP-1', '1.0', Issue::CATEGORY_IN_PROGRESS, ['links' => ['WSC-1', 'WSC-2']]),
            self::issue('WSC-1', '4.1.0 WS', Issue::CATEGORY_IN_PROGRESS),
            self::issue('WSC-2', '4.1.0 WS', Issue::CATEGORY_DONE),
        ]);

        $v = $releases['WSC 4.1.0 WS'];
        self::assertStringContainsString('date de livraison est passée', self::messages($v, 'WSC-1'));
        self::assertArrayNotHasKey('WSC-2', $v->issueAlerts);
        self::assertTrue($v->isOverdue());
    }

    public function testLinkPointingToAnotherVersionIsReported(): void
    {
        self::$versions = ['1.0' => ['1', 10, false], '4.70.0 WS' => ['777', null, false]];
        $date = self::day(5)->format('d/m/Y');
        $calendar = "<table><tr><th>Date</th><th>Version</th></tr><tr><td>$date</td><td><a href=\"https://jira.test/projects/WSC/versions/777\">4.69.0 WS</a></td></tr></table>";

        $release = $this->evaluate([
            self::issue('APP-1', '1.0', Issue::CATEGORY_TODO, ['links' => ['WSC-1']]),
            self::issue('WSC-1', '4.70.0 WS', Issue::CATEGORY_TODO),
        ], $calendar)['WSC 4.70.0 WS'];

        self::assertStringContainsString('le lien « 4.69.0 WS » pointe vers cette version Jira « 4.70.0 WS »', self::messages($release));
        self::assertStringContainsString('lien vers la version Jira « 4.69.0 WS »', $release->getDateSourceLabel());
    }
}
