<?php

namespace App\Release;

/**
 * Rapproche un tag Git d'une version Jira par leur numéro :
 * « v1.4.2 », « portail-1.4.2 », « 1.4.2 » et « 1.4.2 PORTAIL » portent tous le numéro 1.4.2.
 */
final class VersionNumber
{
    /** Numéro de version contenu dans un nom de tag ou de version (null si aucun numéro "x.y[.z…]") */
    public static function of(string $name): ?string
    {
        if (!preg_match('/\d+(?:\.\d+)+/', $name, $m)) {
            return null;
        }

        // 1.04.2 = 1.4.2
        return implode('.', array_map(fn (string $part) => (string) (int) $part, explode('.', $m[0])));
    }

    public static function same(string $a, string $b): bool
    {
        $na = self::of($a);

        return null !== $na ? $na === self::of($b) : 0 === strcasecmp(trim($a), trim($b));
    }
}
