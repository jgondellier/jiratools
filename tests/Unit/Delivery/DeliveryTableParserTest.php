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
        self::assertSame('2026-11-05', $calendar->dateOf('ws-COMMANDE 1.4.2')?->format('Y-m-d'), 'casse ignorée');
        self::assertSame('2026-11-05', $calendar->dateOf('WS-Client 2.0.0', 'WS-Commande')?->format('Y-m-d'), 'nom complet sans le préfixe');
        self::assertSame('2026-11-05', $calendar->dateOf('3.1', 'WS-Stock')?->format('Y-m-d'), '"v" devant le numéro ignoré');
        self::assertNull($calendar->dateOf('1.4', 'WS-Commande'), '1.4 ne correspond pas à 1.4.2');
        self::assertNull($calendar->dateOf('2.0.0', 'WS-Commande'), 'numéro d\'un autre composant');
        self::assertCount(1, $calendar->find('WS-Client 2.1.0'));
        self::assertNull($calendar->dateOf('WS-Client 2.1.0'), 'ligne trouvée mais date illisible');
    }

    public function testVersionNumberFollowedByComponent(): void
    {
        $page = '<table><tr><th>Date</th><th>Version</th><th>Commentaire</th></tr>'
            .'<tr><td>05/11/2026</td><td>1.4.2 WS 2.0.1 WS-REF</td><td>v1.2.3 ne doit pas être lu</td></tr>'
            .'<tr><td>18/11/2026</td><td><p>1.4.3 WS</p><p>2.0.2 WS-REF</p></td><td></td></tr>'
            .'<tr><td>20/11/2026</td><td>1.4.2 WS-REF</td><td></td></tr></table>';
        $rows = (new DeliveryTableParser())->parse($page)['rows'];

        self::assertSame([['version' => '1.4.2', 'component' => 'WS'], ['version' => '2.0.1', 'component' => 'WS-REF']], $rows[0]->entries);
        self::assertSame([['version' => '1.4.3', 'component' => 'WS'], ['version' => '2.0.2', 'component' => 'WS-REF']], $rows[1]->entries);

        $calendar = new DeliveryCalendar($rows);
        self::assertSame('2026-11-05', $calendar->dateOf('1.4.2', 'WS')?->format('Y-m-d'));
        self::assertSame('2026-11-20', $calendar->dateOf('1.4.2', 'WS-REF')?->format('Y-m-d'), 'WS-REF distinct de WS');
        self::assertSame('2026-11-05', $calendar->dateOf('2.0.1 WS-REF')?->format('Y-m-d'), 'composant dans le nom de la version Jira');
        self::assertSame('2026-11-05', $calendar->dateOf('WS-REF 2.0.1')?->format('Y-m-d'), 'ordre inverse dans Jira');
        self::assertNull($calendar->dateOf('2.0.1', 'WS'), '« 2.0.1 WS » n\'est pas « 2.0.1 WS-REF »');
        self::assertSame('2026-11-18', $calendar->dateOf('1.4.3', 'ws')?->format('Y-m-d'), 'casse du composant ignorée');

        // Numéro seul, sans composant paramétré : ambigu entre WS et WS-REF
        self::assertNull($calendar->dateOf('1.4.2'));
        self::assertSame(['WS', 'WS-REF'], $calendar->ambiguousComponents('1.4.2'));
        self::assertSame([], $calendar->ambiguousComponents('1.4.3'));
        self::assertSame('2026-11-18', $calendar->dateOf('1.4.3')?->format('Y-m-d'), 'numéro présent pour un seul composant');
    }
}
