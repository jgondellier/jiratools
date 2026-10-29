<?php

namespace App\Delivery;

/**
 * Calendrier des livraisons lu dans Confluence, et recherche des lignes qui citent une version.
 */
final class DeliveryCalendar
{
    /**
     * @param DeliveryRow[] $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly ?string $pageTitle = null,
        public readonly ?string $pageUrl = null,
        public readonly ?string $error = null,
        public readonly array $headers = [],
    ) {
    }

    /**
     * Lignes qui citent la version (avec le préfixe de composant éventuel), par date croissante.
     *
     * Comparaison sans casse ni accents, "v" devant le numéro ignoré, et sur des mots entiers :
     * « 1.4 » ne correspond pas à « 1.4.2 ».
     *
     * @return DeliveryRow[]
     */
    public function find(string $versionName, ?string $prefix = null): array
    {
        $name = self::normalize($versionName);
        $needles = [];
        if ($prefix && !str_contains($name, self::normalize($prefix))) {
            $needles[] = self::normalize($prefix.' '.$versionName);
        }
        // Le nom seul n'est utilisé sans préfixe que s'il désigne un composant (pas un simple numéro, ambigu)
        if (!$prefix || preg_match('/[a-z]/', preg_replace('/\bv(?=\d)/', '', $name) ?? $name)) {
            $needles[] = $name;
        }
        foreach (array_filter($needles) as $needle) {
            if ([] !== $found = $this->findNeedle($needle)) {
                return $found;
            }
        }

        return [];
    }

    /** @return DeliveryRow[] */
    private function findNeedle(string $needle): array
    {
        $found = [];
        foreach ($this->rows as $row) {
            foreach ($row->versionLines as $line) {
                if (str_contains(' '.self::normalize($line).' ', ' '.$needle.' ')) {
                    $found[] = $row;
                    break;
                }
            }
        }
        usort($found, fn (DeliveryRow $a, DeliveryRow $b) => [$a->date === null, $a->date] <=> [$b->date === null, $b->date]);

        return $found;
    }

    /** Date de livraison d'une version (première ligne datée qui la cite) */
    public function dateOf(string $versionName, ?string $prefix = null): ?\DateTimeImmutable
    {
        foreach ($this->find($versionName, $prefix) as $row) {
            if ($row->date) {
                return $row->date;
            }
        }

        return null;
    }

    public static function normalize(string $text): string
    {
        $text = DeliveryTableParser::fold($text);
        $text = preg_replace('/[^a-z0-9.]+/', ' ', $text) ?? $text;
        // "v1.4.2" => "1.4.2" ; points en fin de mot retirés ("1.4.2." en fin de phrase)
        $text = preg_replace('/\bv(?=\d)/', '', $text) ?? $text;
        $text = preg_replace('/\.(?=\s|$)/', '', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
