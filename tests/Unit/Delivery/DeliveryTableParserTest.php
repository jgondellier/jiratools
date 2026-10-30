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
        self::assertNull($calendar->dateOf('1.4.2'), 'numéro seul : pas rapproché de « WS-Commande 1.4.2 »');
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

        // Numéro seul, sans composant paramétré : jamais rapproché d'une ligne avec composant
        self::assertNull($calendar->dateOf('1.4.2'));
        self::assertNull($calendar->dateOf('1.4.3'), '« 1.4.3 » d\'un autre projet ≠ « 1.4.3 WS »');
        self::assertSame(['WS', 'WS-REF'], $calendar->componentsForNumber('1.4.2'), 'composants proposés pour le paramétrage');
        self::assertSame(['WS'], $calendar->componentsForNumber('1.4.3'));
        self::assertSame([], $calendar->componentsForNumber('1.4.2', 'WS'), 'composant connu : pas de suggestion');
    }

    public function testCellWithoutComponentMatchesAnyComponent(): void
    {
        $page = '<table><tr><th>Date</th><th>Version</th></tr><tr><td>05/11/2026</td><td>1.6.0</td></tr></table>';
        $calendar = new DeliveryCalendar((new DeliveryTableParser())->parse($page)['rows']);

        self::assertSame('2026-11-05', $calendar->dateOf('1.6.0')?->format('Y-m-d'));
        self::assertSame('2026-11-05', $calendar->dateOf('1.6.0', 'WS')?->format('Y-m-d'));
    }

    /**
     * Calendrier de type « Release Train » : colonnes « Leader de Version » et « PV de version »
     * autour de « Version », versions en liens vers Jira, composant en deux mots, version à 4 niveaux.
     */
    public function testReleaseTrainPage(): void
    {
        $result = (new DeliveryTableParser())->parse(file_get_contents(__DIR__.'/../../fixtures/calendrier-release-train.html'));

        self::assertNull($result['error']);
        self::assertCount(1, $result['rows']);
        $row = $result['rows'][0];

        self::assertSame('2026-10-29', $row->date->format('Y-m-d'), 'macro date Confluence');
        self::assertSame(
            ['1.28.0 Batch AB', '4.68.0 WS', '4.54.0 WS-REF', '4.7.63.0 BDD', '4.39.0 GATEWAY', '1.0.0 WS-CLIENT'],
            array_map(fn (array $e) => $e['version'].' '.$e['component'], $row->entries),
            'colonne « Version » et non « Leader de Version »',
        );
        self::assertSame(['11001', '12001', '13001', '14001', '15001', '16001'], array_map('strval', array_keys($row->versionIds)));
        self::assertArrayHasKey('Leader de Version', $row->otherCells);
        self::assertArrayHasKey('PV de version', $row->otherCells);

        $calendar = new DeliveryCalendar($result['rows']);
        self::assertSame('2026-10-29', $calendar->dateOf('n\'importe quel nom', null, '12001')?->format('Y-m-d'), 'rapprochement par identifiant de version');
        self::assertSame('2026-10-29', $calendar->dateOf('4.54.0 WS-REF')?->format('Y-m-d'), 'rapprochement par nom');
        self::assertSame('2026-10-29', $calendar->dateOf('1.28.0 Batch AB')?->format('Y-m-d'), 'composant en deux mots');
        self::assertSame('2026-10-29', $calendar->dateOf('4.7.63.0', 'BDD')?->format('Y-m-d'));
        self::assertNull($calendar->dateOf('4.68.0', 'WS-REF'));
        self::assertNull($calendar->dateOf('9.9.9', null, '99999'), 'identifiant inconnu, nom inconnu');
    }

    public function testHeaderPriority(): void
    {
        $page = '<table><tr><th>Leader de Version</th><th>Date prévue</th><th>Versions livrées</th><th>Date</th></tr>'
            .'<tr><td>Alice</td><td>01/01/2026</td><td>1.0 WS</td><td>05/11/2026</td></tr></table>';
        $result = (new DeliveryTableParser())->parse($page, 'Date', 'Version');

        self::assertSame('2026-11-05', $result['rows'][0]->date->format('Y-m-d'), 'en-tête identique prioritaire');
        self::assertSame(['1.0 WS'], $result['rows'][0]->versionLines, '« Versions livrées » commence par « Version »');
    }
}
