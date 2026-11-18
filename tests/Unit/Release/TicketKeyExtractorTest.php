<?php

namespace App\Tests\Unit\Release;

use App\Release\TicketKeyExtractor;
use PHPUnit\Framework\TestCase;

class TicketKeyExtractorTest extends TestCase
{
    public function testKeysOfKnownProjectsInAnyCase(): void
    {
        self::assertSame(['APP-123', 'WS-7'], TicketKeyExtractor::extract(
            "feature/app-123-export\nMerge branch 'feature/APP-123' into 'develop'\nCorrige WS-7 et UTF-8, voir APP-123",
            ['APP', 'WS'],
        ));
    }

    public function testUnknownProjectsAreIgnored(): void
    {
        self::assertSame(['APP-5'], TicketKeyExtractor::extract('[APP-5] ISO-8601 et SHA-256 + OTHER-9', ['APP']));
    }

    public function testWithoutProjectListOnlyUppercaseKeys(): void
    {
        self::assertSame(['APP-5', 'UTF-8'], TicketKeyExtractor::extract('APP-5 feature/app-6 UTF-8', []));
    }

    public function testKeyBoundaries(): void
    {
        self::assertSame([], TicketKeyExtractor::extract('MYAPP-1 APP-0 APP-12a3 xAPP-4', ['APP']));
        self::assertSame(['APP-12'], TicketKeyExtractor::extract('(APP-012)', ['APP']));
        self::assertSame(['APP-3'], TicketKeyExtractor::extract('fix/APP-3_libelle', ['APP']));
    }
}
