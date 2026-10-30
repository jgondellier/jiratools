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

    private static function version(string $id, string $name, ?int $offset, bool $released = false): array
    {
        return ['id' => $id, 'name' => $name, 'released' => $released, 'archived' => false, 'releaseDate' => null === $offset ? null : self::day($offset), 'projectKey' => ''];
    }

    private static function issue(string $key, string $version, string $category, array $o = []): Issue
    {
        return new Issue(
            $key, 'Résumé', 'u', explode('-', $key)[0], $o['status'] ?? 'Statut', $category, 'Story', null, null, null,
            array_key_exists('assignee', $o) ? $o['assignee'] : 'acc', 'Alice', null, [], $o['labels'] ?? [], [$version], [], $o['points'] ?? null, $o['flagged'] ?? false,
            null, null, null, null,
            versionDetails: [['name' => $version, 'releaseDate' => null, 'released' => false]],
            links: array_map(fn ($k) => ['key' => $k, 'summary' => '', 'status' => '', 'statusCategory' => 'new', 'projectKey' => explode('-', $k)[0], 'type' => 'dépend de'], $o['links'] ?? []),
        );
    }

    /**
     * @return array<string, ReleaseReadiness> par nom de version
     */
    private function evaluate(array $versions, array $issues, string $calendarHtml = '', array $ages = []): array
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getValue')->willReturn([
            'wsProjects' => [['key' => 'WSC', 'prefix' => 'WS']],
            'wsLabels' => ['ws-equipe'],
            'horizonDays' => 30,
            'startDays' => 5,
        ]);

        $jira = $this->createMock(JiraClient::class);
        $jira->method('getVersionsMany')->willReturn($versions);
        $jira->method('versionUrl')->willReturn('https://jira/v');
        $jira->method('searchMany')->willReturnCallback(function (array $searches) use ($issues) {
            $this->searches = $searches;

            return [
                'open' => array_values(array_filter($issues, fn (Issue $i) => !$i->isDone())),
                'done' => array_values(array_filter($issues, fn (Issue $i) => $i->isDone())),
            ];
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

    public function testVersionSelectionAndDates(): void
    {
        $versions = [
            'APP' => [
                self::version('1', '1.0', 10),
                self::version('2', '1.1', 60),            // hors horizon
                self::version('3', '0.9', -5, true),      // livrée
                self::version('4', '2.0', null),          // sans date
                self::version('5', '1.2', 50),            // hors horizon dans Jira, mais dans 20 j dans Confluence
            ],
            'WSC' => [self::version('6', '1.4.2', -3)],   // en retard
        ];
        $date = self::day(20)->format('d/m/Y');
        $calendar = "<table><tr><th>Date</th><th>Version</th></tr><tr><td>$date</td><td>1.2</td></tr></table>";

        $releases = $this->evaluate($versions, [], $calendar);

        self::assertSame(['WSC 1.4.2', 'APP 1.0', 'APP 1.2', 'APP 2.0'], array_keys($releases), 'triées par date, sans date à la fin');
        self::assertSame(self::day(20)->format('Y-m-d'), $releases['APP 1.2']->getDate()->format('Y-m-d'), 'la date Confluence fait foi');
        self::assertStringContainsString('≠ date Confluence', self::messages($releases['APP 1.2']));
        self::assertStringContainsString('Absente du calendrier', self::messages($releases['APP 1.0']));
        self::assertStringContainsString('Pas de date de livraison', self::messages($releases['APP 2.0']));
        self::assertSame(ReleaseReadiness::ERROR, $releases['WSC 1.4.2']->getLevel());
        self::assertStringContainsString('Date de livraison dépassée', self::messages($releases['WSC 1.4.2']));
        self::assertSame(11, $releases['APP 1.0']->daysLeft);
    }

    public function testIssueRules(): void
    {
        $versions = [
            'APP' => [self::version('1', '1.0', 3), self::version('2', '1.1', 8)],
            'WSC' => [self::version('3', '1.4.2', 6)],
        ];
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

        $releases = $this->evaluate($versions, $issues, '', $ages);
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

        // Requête : mes projets + tickets WS portant l'étiquette (pas de board dans ce test)
        self::assertStringStartsWith(
            'fixVersion in (1, 2, 3) AND (((project = "APP")) OR (project in ("WSC") AND labels in ("ws-equipe"))) AND statusCategory != Done',
            $this->searches['open'][0],
        );
        self::assertTrue($this->searches['open'][2], 'historique demandé pour les tickets non terminés');
        self::assertFalse($this->searches['done'][2]);
    }

    public function testOverdueVersionFlagsOpenIssues(): void
    {
        $releases = $this->evaluate(
            ['APP' => [self::version('1', '1.0', -2)]],
            [self::issue('APP-1', '1.0', Issue::CATEGORY_IN_PROGRESS), self::issue('APP-2', '1.0', Issue::CATEGORY_DONE)],
        );

        self::assertStringContainsString('date de livraison est passée', self::messages($releases['APP 1.0'], 'APP-1'));
        self::assertArrayNotHasKey('APP-2', $releases['APP 1.0']->issueAlerts);
        self::assertTrue($releases['APP 1.0']->isOverdue());
    }
}
