<?php

namespace App\Tests\Unit;

use App\Jira\JiraClient;
use App\Service\ReleaseNoteService;
use PHPUnit\Framework\TestCase;

class ReleaseNoteServiceTest extends TestCase
{
    public function testGroupsByLabelBugsLastAndOthersAtTheEnd(): void
    {
        $service = new ReleaseNoteService($this->createMock(JiraClient::class), 'technique, interne');

        $groups = $service->group([
            IssueFactory::create(['key' => 'APP-3', 'labels' => ['export'], 'type' => 'Bug']),
            IssueFactory::create(['key' => 'APP-1', 'labels' => ['export']]),
            IssueFactory::create(['key' => 'APP-2', 'labels' => []]),
            IssueFactory::create(['key' => 'APP-4', 'labels' => ['Technique']]),
            IssueFactory::create(['key' => 'APP-5', 'labels' => ['authentification', 'export']]),
        ]);

        self::assertSame(['authentification', 'export', ReleaseNoteService::NO_LABEL], array_keys($groups));
        self::assertSame(['APP-1', 'APP-5', 'APP-3'], array_map(fn ($i) => $i->key, $groups['export']));
        self::assertSame(['APP-2', 'APP-4'], array_map(fn ($i) => $i->key, $groups[ReleaseNoteService::NO_LABEL]));
    }

    public function testMarkdown(): void
    {
        $service = new ReleaseNoteService($this->createMock(JiraClient::class));
        $md = $service->toMarkdown('RN 1.0', ['mise_a-jour' => [IssueFactory::create(['key' => 'APP-9', 'summary' => 'Truc'])]]);

        self::assertStringContainsString('# RN 1.0', $md);
        self::assertStringContainsString('## Mise a jour', $md);
        self::assertStringContainsString('- [APP-9](https://jira.test/browse/APP-9) Truc', $md);
    }
}
