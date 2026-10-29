<?php

namespace App\Delivery;

/**
 * Lecture tolérante d'une date saisie dans une page Confluence :
 * 12/03/2026, 12/03/26, 12-03-2026, 12.03.2026, 2026-03-12, 12 mars 2026, 1er mars 2026, 12 Mar 2026…
 * La première date trouvée dans le texte est retenue (ex. « 12/03/2026 (prévisionnel) »).
 */
final class DateParser
{
    private const MONTHS = [
        'janvier' => 1, 'janv' => 1, 'jan' => 1, 'january' => 1,
        'fevrier' => 2, 'fevr' => 2, 'fev' => 2, 'feb' => 2, 'february' => 2,
        'mars' => 3, 'mar' => 3, 'march' => 3,
        'avril' => 4, 'avr' => 4, 'apr' => 4, 'april' => 4,
        'mai' => 5, 'may' => 5,
        'juin' => 6, 'jun' => 6, 'june' => 6,
        'juillet' => 7, 'juil' => 7, 'jul' => 7, 'july' => 7,
        'aout' => 8, 'aug' => 8, 'august' => 8,
        'septembre' => 9, 'sept' => 9, 'sep' => 9, 'september' => 9,
        'octobre' => 10, 'oct' => 10, 'october' => 10,
        'novembre' => 11, 'nov' => 11, 'november' => 11,
        'decembre' => 12, 'dec' => 12, 'december' => 12,
    ];

    public static function parse(?string $text): ?\DateTimeImmutable
    {
        $text = self::normalize((string) $text);
        if ('' === $text) {
            return null;
        }

        // 2026-03-12
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $text, $m)) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        // 12/03/2026, 12-03-26, 12.03.2026
        if (preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})\b/', $text, $m)) {
            $year = (int) $m[3];

            return self::make($year < 100 ? 2000 + $year : $year, (int) $m[2], (int) $m[1]);
        }
        // 12 mars 2026, 1er mars 2026, 12 mar. 2026
        if (preg_match('/\b(\d{1,2})(?:er)?\s+([a-z]+)\.?\s+(\d{4})\b/', $text, $m) && isset(self::MONTHS[$m[2]])) {
            return self::make((int) $m[3], self::MONTHS[$m[2]], (int) $m[1]);
        }
        // March 12, 2026
        if (preg_match('/\b([a-z]+)\.?\s+(\d{1,2}),?\s+(\d{4})\b/', $text, $m) && isset(self::MONTHS[$m[1]])) {
            return self::make((int) $m[3], self::MONTHS[$m[1]], (int) $m[2]);
        }

        return null;
    }

    private static function make(int $year, int $month, int $day): ?\DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $month, $day)) ?: null;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'û' => 'u', 'ù' => 'u', 'ô' => 'o', 'î' => 'i', 'à' => 'a', "\u{a0}" => ' ']);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
