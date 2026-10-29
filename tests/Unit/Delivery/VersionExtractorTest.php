<?php

namespace App\Tests\Unit\Delivery;

use App\Delivery\VersionExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionExtractorTest extends TestCase
{
    public static function texts(): iterable
    {
        yield 'numéro puis composant' => ['1.4.2 WS 2.0.1 WS-REF', ['1.4.2 WS', '2.0.1 WS-REF']];
        yield 'séparés par une virgule' => ['1.4.2 WS, 2.0.1 WS-REF', ['1.4.2 WS', '2.0.1 WS-REF']];
        yield 'sur plusieurs lignes' => ["1.4.2 WS\n2.0.1 WS-REF", ['1.4.2 WS', '2.0.1 WS-REF']];
        yield 'composant puis numéro' => ['WS 1.4.2 / WS-REF v2.0.1', ['1.4.2 WS', '2.0.1 WS-REF']];
        yield 'composant puis numéro, WS-REF en premier' => ['WS-REF 2.0.1 - WS 1.4.2', ['2.0.1 WS-REF', '1.4.2 WS']];
        yield 'même numéro, deux composants' => ['1.4.2 WS-REF 1.4.2 WS', ['1.4.2 WS-REF', '1.4.2 WS']];
        yield 'commentaire après' => ['1.4.2 WS (correctif urgent)', ['1.4.2 WS']];
        yield 'deux points' => ['WS : 1.4.2', ['1.4.2 WS']];
        yield 'numéro seul' => ['1.4.2', ['1.4.2 ?']];
        yield 'texte avant' => ['Livraison du 12.03 : 1.4.2 WS', ['12.03 ?', '1.4.2 WS']];
        yield 'numéro à 4 niveaux' => ['1.4.2.7 WS', ['1.4.2.7 WS']];
        yield 'doublon' => ['1.4.2 WS 1.4.2 WS', ['1.4.2 WS']];
        yield 'sans version' => ['à définir', []];
    }

    #[DataProvider('texts')]
    public function testExtract(string $text, array $expected): void
    {
        $actual = array_map(fn (array $e) => $e['version'].' '.($e['component'] ?? '?'), VersionExtractor::extract($text));

        self::assertSame($expected, $actual);
    }

    public function testFoldComponent(): void
    {
        self::assertSame('wsref', VersionExtractor::foldComponent('WS-REF'));
        self::assertSame('wsref', VersionExtractor::foldComponent('ws_ref'));
        self::assertNotSame(VersionExtractor::foldComponent('WS'), VersionExtractor::foldComponent('WS-REF'));
    }
}
