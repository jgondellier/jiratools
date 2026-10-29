<?php

namespace App\Tests\Unit\Delivery;

use App\Delivery\DateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateParserTest extends TestCase
{
    public static function dates(): iterable
    {
        yield ['12/03/2026', '2026-03-12'];
        yield ['5/3/26', '2026-03-05'];
        yield ['12-03-2026', '2026-03-12'];
        yield ['12.03.2026', '2026-03-12'];
        yield ['2026-03-12', '2026-03-12'];
        yield ['12 mars 2026', '2026-03-12'];
        yield ['1er décembre 2026 (prévisionnel)', '2026-12-01'];
        yield ['Lundi 12 Févr. 2026', '2026-02-12'];
        yield ['March 12, 2026', '2026-03-12'];
        yield ['Livraison le 03/11/2026 puis le 10/11/2026', '2026-11-03'];
        yield ['à définir', null];
        yield ['31/02/2026', null];
        yield ['', null];
    }

    #[DataProvider('dates')]
    public function testParse(string $text, ?string $expected): void
    {
        self::assertSame($expected, DateParser::parse($text)?->format('Y-m-d'));
    }
}
