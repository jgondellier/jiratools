<?php

namespace App\Delivery;

/**
 * Une ligne du tableau des livraisons de la page Confluence.
 */
final class DeliveryRow
{
    /**
     * @param string[]              $versionLines contenu de la colonne Version, ligne par ligne
     * @param array<string, string> $otherCells   autres colonnes (en-tête => texte)
     * @param array<int, array{version: string, component: ?string}> $entries versions reconnues dans la colonne Version
     * @param array<string, string> $versionIds identifiants des versions Jira liées dans la cellule => texte du lien
     */
    public function __construct(
        public readonly int $number,
        public readonly ?\DateTimeImmutable $date,
        public readonly string $rawDate,
        public readonly array $versionLines,
        public readonly array $otherCells,
        public readonly array $entries = [],
        public readonly array $versionIds = [],
    ) {
    }

    public function getVersionText(): string
    {
        return implode("\n", $this->versionLines);
    }
}
