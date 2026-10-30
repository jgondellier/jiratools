<?php

namespace App\Delivery;

/**
 * Extrait les couples (numéro de version, composant) d'un texte libre, par exemple :
 *   « 1.4.2 WS 2.0.1 WS-REF », « 1.4.2 WS, 2.0.1 WS-REF », « WS 1.4.2 / WS-REF v2.0.1 », « 1.4.2 ».
 *
 * L'ordre (numéro puis composant, ou composant puis numéro) est déduit du texte.
 */
final class VersionExtractor
{
    private const NUMBER = 'v?(\d+(?:\.\d+){1,3})';
    private const COMPONENT = '(%s[A-Za-z0-9_]*(?:[-\/][A-Za-z0-9_]+)*)';
    /** Après un numéro, le composant peut faire plusieurs mots si les suivants commencent par une majuscule (« Batch AB ») */
    private const COMPONENT_WORDS = '(%s[A-Za-z0-9_]*(?:[-\/][A-Za-z0-9_]+)*(?:[ \t\x{a0}]+[A-Z][A-Za-z0-9_]*(?:[-\/][A-Za-z0-9_]+)*)*)';

    /**
     * @param bool $uppercaseComponents un composant doit commencer par une majuscule (texte libre d'une page,
     *                                  où « correctif 4.68.0 » n'est pas un composant) ; false pour un nom de version Jira
     *
     * @return array<int, array{version: string, component: ?string}>
     */
    public static function extract(string $text, bool $uppercaseComponents = true): array
    {
        $first = $uppercaseComponents ? '[A-Z]' : '[A-Za-z]';
        $numberFirst = '/(?<![\w.])'.self::NUMBER.'(?:[ \t\x{a0}_-]*'.sprintf(self::COMPONENT_WORDS, $first).')?/u';
        $componentFirst = '/(?<![\w-])'.sprintf(self::COMPONENT, $first).'[ \t\x{a0}]*[:\-]?[ \t\x{a0}]*'.self::NUMBER.'(?![\w.])/u';

        preg_match_all($numberFirst, $text, $nf, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        preg_match_all($componentFirst, $text, $cf, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        // Les numéros suivis d'un composant uniquement, pour comparer les deux lectures
        $nfWithComponent = array_values(array_filter($nf, fn (array $m) => isset($m[2]) && '' !== $m[2][0]));

        // Lecture retenue : celle qui trouve le plus de composants "nommés" (avec une majuscule, ex. WS, WS-REF),
        // à égalité celle qui commence le plus tôt dans le texte (« du 12.03 » n'est pas un composant)
        $named = fn (array $matches, int $group) => \count(array_filter($matches, fn (array $m) => isset($m[$group]) && preg_match('/\p{Lu}/u', $m[$group][0])));
        $scoreCf = $named($cf, 1);
        $scoreNf = $named($nfWithComponent, 2);
        $useComponentFirst = [] !== $cf && ($scoreCf !== $scoreNf
            ? $scoreCf > $scoreNf
            : ([] === $nfWithComponent || $cf[0][0][1] < $nfWithComponent[0][0][1]));

        $entries = [];
        if ($useComponentFirst) {
            foreach ($cf as $m) {
                $entries[] = ['version' => $m[2][0], 'component' => $m[1][0]];
            }
        } else {
            foreach ($nf as $m) {
                $component = isset($m[2]) && '' !== $m[2][0] ? $m[2][0] : null;
                $entries[] = ['version' => $m[1][0], 'component' => $component];
            }
        }

        // Dédoublonnage
        $unique = [];
        foreach ($entries as $entry) {
            $unique[$entry['version'].'|'.self::foldComponent($entry['component'])] = $entry;
        }

        return array_values($unique);
    }

    /** Composant comparable : minuscules, sans accents ni séparateurs (« WS-REF » = « ws_ref » = « WS REF ») */
    public static function foldComponent(?string $component): string
    {
        return preg_replace('/[^a-z0-9]+/', '', DeliveryTableParser::fold((string) $component)) ?? '';
    }
}
