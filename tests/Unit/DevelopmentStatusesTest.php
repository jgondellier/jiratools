<?php

namespace App\Tests\Unit;

use App\Jira\Issue;
use App\Repository\SettingRepository;
use App\Service\DevelopmentStatuses;
use PHPUnit\Framework\TestCase;

class DevelopmentStatusesTest extends TestCase
{
    private function service(array $stored): DevelopmentStatuses
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getValue')->willReturn($stored);

        return new DevelopmentStatuses($repo);
    }

    public function testFallsBackToInProgressCategory(): void
    {
        $service = $this->service([]);

        self::assertFalse($service->isConfigured());
        self::assertTrue($service->isDevelopment(IssueFactory::create(['status' => 'Code Review'])));
        self::assertFalse($service->isDevelopment(IssueFactory::create(['status' => 'A faire', 'statusCategory' => Issue::CATEGORY_TODO])));
    }

    public function testOnlyConfiguredStatusesCaseInsensitive(): void
    {
        $service = $this->service(['En cours', 'En développement']);

        self::assertTrue($service->isDevelopment(IssueFactory::create(['status' => 'en cours'])));
        self::assertTrue($service->isDevelopment(IssueFactory::create(['status' => 'En développement', 'statusCategory' => Issue::CATEGORY_TODO])));
        self::assertFalse($service->isDevelopment(IssueFactory::create(['status' => 'Code Review'])));
    }

    public function testSetStatusesCleansAndDeduplicates(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->expects(self::once())->method('setValue')->with(DevelopmentStatuses::SETTING, ['En cours', 'En dev']);

        (new DevelopmentStatuses($repo))->setStatuses(['En cours', ' en cours ', '', 'En dev']);
    }
}
