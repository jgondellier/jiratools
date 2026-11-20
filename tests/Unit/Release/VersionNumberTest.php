<?php

namespace App\Tests\Unit\Release;

use App\Release\VersionNumber;
use PHPUnit\Framework\TestCase;

class VersionNumberTest extends TestCase
{
    public function testNumberOfTagsAndVersions(): void
    {
        self::assertSame('1.4.2', VersionNumber::of('v1.4.2'));
        self::assertSame('1.4.2', VersionNumber::of('portail-1.4.2'));
        self::assertSame('1.4.2', VersionNumber::of('1.4.2 PORTAIL'));
        self::assertSame('1.4.2', VersionNumber::of('release/1.04.2'));
        self::assertSame('2026.11', VersionNumber::of('Release 2026.11'));
        self::assertNull(VersionNumber::of('sprint-12'));
    }

    public function testSame(): void
    {
        self::assertTrue(VersionNumber::same('v1.4.2', '1.4.2 PORTAIL'));
        self::assertFalse(VersionNumber::same('v1.4', '1.4.2'));
        self::assertFalse(VersionNumber::same('v1.4.2', '1.4.20'));
        self::assertTrue(VersionNumber::same('Hiver', 'hiver'), 'Sans numéro : comparaison du nom');
    }
}
