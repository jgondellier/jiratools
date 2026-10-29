<?php

namespace App\Tests\Unit\Delivery;

use App\Delivery\DeliveryCalendar;
use App\Delivery\DeliveryTableParser;
use PHPUnit\Framework\TestCase;

class DeliveryTableParserTest extends TestCase
{
    private const PAGE = <<<'HTML'
        <p>Intro</p>
        <table><tbody><tr><th>Projet</th><th>Responsable</th></tr><tr><td>X</td><td>Y</td></tr></tbody></table>
        <table><tbody>
        <tr><th><p>Date de livraison</p></th><th><p>Versions</p></th><th>Environnement</th></tr>
        <tr><td rowspan="2"><p><time datetime="2026-11-05" /></p></td><td><p>WS-Commande 1.4.2<br/>WS-Client 2.0.0</p></td><td>PROD</td></tr>
        <tr><td><ul><li>WS-Stock v3.1</li></ul></td><td>PROD</td></tr>
        <tr><td>12 décembre 2026</td><td>WS-Commande 1.5.0</td><td>RECETTE</td></tr>
        <tr><td>à définir</td><td>WS-Client 2.1.0</td><td></td></tr>
        <tr><td></td><td></td><td>ligne vide ignorée</td></tr>
        </tbody></table>
        HTML;

    public function testParsesTheMatchingTableWithMergedCells(): void
    {
        $result = (new DeliveryTableParser())->parse(self::PAGE, 'date', 'VERSION');

        self::assertNull($result['error']);
        self::assertSame(['Date de livraison', 'Versions', 'Environnement'], $result['headers']);
        self::assertCount(4, $result['rows']);

        [$first, $second, $third, $fourth] = $result['rows'];
        self::assertSame('2026-11-05', $first->date->format('Y-m-d'));
        self::assertSame(['WS-Commande 1.4.2', 'WS-Client 2.0.0'], $first->versionLines);
        self::assertSame(['Environnement' => 'PROD'], $first->otherCells);
        self::assertSame('2026-11-05', $second->date->format('Y-m-d'), 'date fusionnée (rowspan) reprise');
        self::assertSame(['WS-Stock v3.1'], $second->versionLines);
        self::assertSame('PROD', $second->otherCells['Environnement'], 'colonnes décalées correctement après le rowspan');
        self::assertSame('2026-12-12', $third->date->format('Y-m-d'));
        self::assertNull($fourth->date);
        self::assertSame('à définir', $fourth->rawDate);
    }

    public function testExplainsWhenNoTableMatches(): void
    {
        $result = (new DeliveryTableParser())->parse(self::PAGE, 'Jalon', 'Version');

        self::assertSame([], $result['rows']);
        self::assertStringContainsString('« Projet | Responsable »', $result['error']);
        self::assertStringContainsString('Date de livraison', $result['error']);
    }

    public function testCalendarMatching(): void
    {
        $calendar = new DeliveryCalendar((new DeliveryTableParser())->parse(self::PAGE)['rows']);

        self::assertSame('2026-11-05', $calendar->dateOf('1.4.2', 'WS-Commande')?->format('Y-m-d'), 'préfixe ajouté au numéro');
        self::assertSame('2026-11-05', $calendar->dateOf('ws commande 1.4.2')?->format('Y-m-d'), 'casse et tiret ignorés');
        self::assertSame('2026-11-05', $calendar->dateOf('WS-Client 2.0.0', 'WS-Commande')?->format('Y-m-d'), 'nom complet sans le préfixe');
        self::assertSame('2026-11-05', $calendar->dateOf('3.1', 'WS-Stock')?->format('Y-m-d'), '"v" devant le numéro ignoré');
        self::assertNull($calendar->dateOf('1.4', 'WS-Commande'), '1.4 ne correspond pas à 1.4.2');
        self::assertNull($calendar->dateOf('2.0.0', 'WS-Commande'), 'numéro d\'un autre composant');
        self::assertCount(1, $calendar->find('WS-Client 2.1.0'));
        self::assertNull($calendar->dateOf('WS-Client 2.1.0'), 'ligne trouvée mais date illisible');
    }
}
