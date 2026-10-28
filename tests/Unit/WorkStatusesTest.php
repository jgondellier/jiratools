<?php

namespace App\Tests\Unit;

use App\Jira\Issue;
use App\Repository\SettingRepository;
use App\Service\WorkStatuses;
use PHPUnit\Framework\TestCase;

class WorkStatusesTest extends TestCase
{
    private function service(array $settings): WorkStatuses
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getValue')->willReturnCallback(fn (string $name, mixed $default = null) => $settings[$name] ?? $default);

        return new WorkStatuses($repo);
    }

    public function testFallsBackToInProgressCategory(): void
    {
        $service = $this->service([]);

        self::assertSame([], $service->getConfig());
        self::assertTrue($service->isWork(IssueFactory::create(['status' => 'Code Review']), 'dev'));
        self::assertFalse($service->isWork(IssueFactory::create(['status' => 'A faire', 'statusCategory' => Issue::CATEGORY_TODO]), 'qa'));
    }

    public function testRoleOverridesDefaultAndOtherRolesInherit(): void
    {
        $service = $this->service([WorkStatuses::SETTING => [
            'default' => ['En cours', 'En développement'],
            'qa' => ['En test'],
        ]]);

        $dev = IssueFactory::create(['status' => 'en développement', 'statusCategory' => Issue::CATEGORY_TODO]);
        $test = IssueFactory::create(['status' => 'En test']);

        self::assertTrue($service->isWork($dev, 'dev'));
        self::assertTrue($service->isWork($dev, 'lead'), 'lead hérite du défaut');
        self::assertFalse($service->isWork($test, 'dev'));
        self::assertTrue($service->isWork($test, 'qa'));
        self::assertFalse($service->isWork($dev, 'qa'));
        self::assertTrue($service->hasOwnStatuses('qa'));
        self::assertFalse($service->hasOwnStatuses('dev'));
        self::assertSame(['En cours', 'En développement', 'En test'], $service->getAllStatuses());
    }

    public function testLegacySettingIsUsedAsDefault(): void
    {
        $service = $this->service(['development_statuses' => ['En cours']]);

        self::assertSame(['default' => ['En cours']], $service->getConfig());
        self::assertSame(['En cours'], $service->getStatuses('qa'));
    }

    public function testSetConfigCleansAndIgnoresUnknownRoles(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->expects(self::once())->method('setValue')->with(WorkStatuses::SETTING, [
            'default' => ['En cours', 'En dev'],
            'qa' => ['En test'],
        ]);

        (new WorkStatuses($repo))->setConfig([
            'default' => ['En cours', ' en cours ', '', 'En dev'],
            'qa' => ['En test'],
            'po' => [],
            'pirate' => ['Hack'],
        ]);
    }
}
