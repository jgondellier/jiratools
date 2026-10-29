<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Jira\Sprint;
use App\Service\IssueViewFactory;
use App\Service\SprintService;
use App\Service\WorkingTime;
use PHPUnit\Framework\TestCase;

class SprintServiceTest extends TestCase
{
    public function testVelocityUsesASingleSearch(): void
    {
        $s10 = new Sprint(10, 'Sprint 10', 'closed', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-09-15 18:00'), null, 42);
        $s11 = new Sprint(11, 'Sprint 11', 'closed', new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-09-29'), new \DateTimeImmutable('2026-09-29 18:00'), null, 42);

        $done = fn (string $key, array $sprints, string $resolved, float $points) => new Issue(
            $key, 's', 'u', 'APP', 'Terminé', Issue::CATEGORY_DONE, 'Story', null, null, null, null, null, null,
            [], [], [], [], $points, false, null, null, new \DateTimeImmutable($resolved), null, null, null, $sprints,
        );
        $issues = [
            $done('APP-1', [10], '2026-09-10', 3),
            // Reporté du sprint 10 au 11, terminé dans le 11 : compte pour le 11 seulement
            $done('APP-2', [10, 11], '2026-09-20', 5),
            IssueFactory::create(['key' => 'APP-3']) /* non terminé, sans sprint */,
        ];

        $jira = $this->createMock(JiraClient::class);
        $jira->method('getSprints')->willReturn([$s10, $s11]);
        $jira->expects(self::once())->method('search')
            ->with(self::stringStartsWith('sprint in (11, 10) AND project = "APP"'))
            ->willReturn($issues);

        $service = new SprintService($jira, $this->createMock(IssueViewFactory::class), new WorkingTime());
        $velocity = $service->getVelocity((new Project())->setJiraProjectKey('APP')->setJiraBoardId(42));

        self::assertSame(['Sprint 10', 'Sprint 11'], array_map(fn ($v) => $v['sprint']->name, $velocity));
        self::assertSame([1, 1], array_column($velocity, 'done'));
        self::assertSame([3.0, 5.0], array_column($velocity, 'points'));
        self::assertSame([2, 1], array_column($velocity, 'total'));
    }
}
