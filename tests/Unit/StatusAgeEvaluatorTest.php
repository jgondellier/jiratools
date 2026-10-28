<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Entity\StatusRule;
use App\Jira\Issue;
use App\Repository\StatusRuleRepository;
use App\Service\StatusAge;
use App\Service\StatusAgeEvaluator;
use App\Service\WorkingTime;
use PHPUnit\Framework\TestCase;

class StatusAgeEvaluatorTest extends TestCase
{
    private function evaluator(array $rules): StatusAgeEvaluator
    {
        $repo = $this->createMock(StatusRuleRepository::class);
        $repo->method('findAll')->willReturn($rules);

        return new StatusAgeEvaluator($repo, new WorkingTime(false), 3, 5);
    }

    public function testDefaultThresholdsForInProgress(): void
    {
        $evaluator = $this->evaluator([]);

        $fresh = IssueFactory::create(['statusSince' => new \DateTimeImmutable('-1 day')]);
        self::assertSame(StatusAge::OK, $evaluator->evaluate($fresh)->level);

        $warning = IssueFactory::create(['statusSince' => new \DateTimeImmutable('-4 days')]);
        self::assertSame(StatusAge::WARNING, $evaluator->evaluate($warning)->level);

        $critical = IssueFactory::create(['statusSince' => new \DateTimeImmutable('-6 days')]);
        self::assertSame(StatusAge::CRITICAL, $evaluator->evaluate($critical)->level);
    }

    public function testNoDefaultAlertForTodoOrDone(): void
    {
        $evaluator = $this->evaluator([]);
        $todo = IssueFactory::create(['statusCategory' => Issue::CATEGORY_TODO, 'statusSince' => new \DateTimeImmutable('-30 days')]);
        self::assertSame(StatusAge::OK, $evaluator->evaluate($todo)->level);
    }

    public function testRuleIsCaseInsensitiveAndProjectRuleWins(): void
    {
        $project = (new Project())->setName('P')->setJiraProjectKey('APP');
        $ref = new \ReflectionProperty(Project::class, 'id');
        $ref->setValue($project, 7);

        $global = (new StatusRule())->setStatusName('code review')->setWarningDays(1)->setCriticalDays(2);
        $specific = (new StatusRule())->setStatusName('Code Review')->setWarningDays(5)->setCriticalDays(10)->setProject($project);
        $evaluator = $this->evaluator([$global, $specific]);

        $issue = IssueFactory::create(['status' => 'Code Review', 'statusSince' => new \DateTimeImmutable('-3 days')]);
        self::assertSame(StatusAge::OK, $evaluator->evaluate($issue, $project)->level, 'la règle projet (5 j) prime');

        $otherProject = IssueFactory::create(['status' => 'Code Review', 'projectKey' => 'OTHER', 'statusSince' => new \DateTimeImmutable('-3 days')]);
        self::assertSame(StatusAge::CRITICAL, $evaluator->evaluate($otherProject)->level, 'la règle globale (2 j) s\'applique');
    }

    public function testRuleAppliesToTodoStatus(): void
    {
        $rule = (new StatusRule())->setStatusName('Ready for dev')->setWarningDays(10)->setCriticalDays(20);
        $evaluator = $this->evaluator([$rule]);
        $issue = IssueFactory::create(['status' => 'Ready for dev', 'statusCategory' => Issue::CATEGORY_TODO, 'statusSince' => new \DateTimeImmutable('-12 days')]);
        self::assertSame(StatusAge::WARNING, $evaluator->evaluate($issue)->level);
        self::assertSame(['Ready for dev'], $evaluator->getRuledStatuses());
    }
}
