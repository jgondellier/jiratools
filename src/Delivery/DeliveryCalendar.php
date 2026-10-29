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
     * Lignes qui citent la version, par date croissante.
     *
     * La version Jira est décomposée en numéro + composant (« 1.4.2 WS », « WS-REF 2.0.1 ») ; si elle ne porte
     * que le numéro (« 1.4.2 »), le composant paramétré pour son projet est utilisé. Le numéro doit être
     * identique (« 1.4 » ≠ « 1.4.2 ») et le composant aussi (« WS » ≠ « WS-REF », « 1.4.2 » ≠ « 1.4.2 WS »),
     * sans tenir compte de la casse.
     *
     * @return DeliveryRow[]
     */
    public function find(string $versionName, ?string $component = null): array
    {
        $wanted = self::wanted($versionName, $component);
        if (null === $wanted) {
            return $this->findText($versionName, $component);
        }

        $found = [];
        foreach ($this->rows as $row) {
            if ([] === $row->entries) {
                // Cellule sans numéro de version reconnaissable : comparaison sur le texte
                if ([] !== $this->filterText([$row], $versionName, $component)) {
                    $found[] = $row;
                }
                continue;
            }
            foreach ($row->entries as $entry) {
                if (self::matches($entry, $wanted)) {
                    $found[] = $row;
                    break;
                }
            }
        }

        return self::sortByDate($found);
    }

    /**
     * Pour une version sans composant (« 1.4.2 ») non trouvée telle quelle : composants pour lesquels
     * ce numéro figure dans le calendrier (« 1.4.2 WS », « 1.4.2 WS-REF »), afin d'aider au paramétrage.
     *
     * @return string[]
     */
    public function componentsForNumber(string $versionName, ?string $component = null): array
    {
        $wanted = self::wanted($versionName, $component);
        if (null === $wanted || null !== $wanted['component']) {
            return [];
        }
        $components = [];
        foreach ($this->rows as $row) {
            foreach ($row->entries as $entry) {
                if ($entry['version'] === $wanted['version'] && null !== $entry['component']) {
                    $components[VersionExtractor::foldComponent($entry['component'])] = $entry['component'];
                }
            }
        }

        return array_values($components);
    }

    /** @return array{version: string, component: ?string}|null */
    private static function wanted(string $versionName, ?string $component): ?array
    {
        $entries = VersionExtractor::extract($versionName);
        if (1 !== \count($entries)) {
            return null;
        }
        $wanted = $entries[0];
        $wanted['component'] ??= ($component ?: null);

        return $wanted;
    }

    /**
     * Même numéro et même composant. Un numéro seul ne correspond qu'à une ligne sans composant
     * (« 1.5.0 » de mon projet ≠ « 1.5.0 WS ») ; une ligne sans composant correspond à tout composant.
     */
    private static function matches(array $entry, array $wanted): bool
    {
        if ($entry['version'] !== $wanted['version']) {
            return false;
        }
        if (null === $entry['component']) {
            return true;
        }

        return null !== $wanted['component']
            && VersionExtractor::foldComponent($entry['component']) === VersionExtractor::foldComponent($wanted['component']);
    }

    /**
     * Recherche sur le texte, pour les versions sans numéro (ex. « Release Printemps »).
     *
     * @return DeliveryRow[]
     */
    private function findText(string $versionName, ?string $component): array
    {
        return self::sortByDate($this->filterText($this->rows, $versionName, $component));
    }

    /**
     * @param DeliveryRow[] $rows
     *
     * @return DeliveryRow[]
     */
    private function filterText(array $rows, string $versionName, ?string $component): array
    {
        $needles = [self::normalize($versionName)];
        if ($component) {
            array_unshift($needles, self::normalize($component.' '.$versionName), self::normalize($versionName.' '.$component));
        }
        $found = [];
        foreach ($rows as $row) {
            foreach ($row->versionLines as $line) {
                $haystack = ' '.self::normalize($line).' ';
                foreach (array_filter($needles) as $needle) {
                    if (str_contains($haystack, ' '.$needle.' ')) {
                        $found[] = $row;
                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param DeliveryRow[] $rows
     *
     * @return DeliveryRow[]
     */
    private static function sortByDate(array $rows): array
    {
        usort($rows, fn (DeliveryRow $a, DeliveryRow $b) => [null === $a->date, $a->date] <=> [null === $b->date, $b->date]);

        return $rows;
    }

    /** Date de livraison d'une version (première ligne datée qui la cite) */
    public function dateOf(string $versionName, ?string $component = null): ?\DateTimeImmutable
    {
        foreach ($this->find($versionName, $component) as $row) {
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
