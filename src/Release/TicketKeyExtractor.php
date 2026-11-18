<?php

namespace App\Release;

/**
 * Repère les clés de tickets Jira ("APP-123") dans un texte : message de commit, titre ou branche de merge request.
 */
final class TicketKeyExtractor
{
    /**
     * @param string[] $projectKeys clés de projets reconnues (sans tenir compte de la casse : "feature/app-123-export").
     *                              Vide : toute clé écrite en majuscules.
     *
     * @return string[] clés en majuscules, dans l'ordre d'apparition, sans doublon
     */
    public static function extract(string $text, array $projectKeys = []): array
    {
        $allowed = array_map('strtoupper', $projectKeys);
        $flags = [] === $allowed ? '' : 'i';
        if (!preg_match_all('/(?<![A-Za-z0-9_])([A-Za-z][A-Za-z0-9_]*)-(\d+)(?![0-9A-Za-z])/'.$flags, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $keys = [];
        foreach ($matches as [, $project, $number]) {
            if ([] === $allowed ? !preg_match('/^[A-Z][A-Z0-9_]+$/', $project) : !\in_array(strtoupper($project), $allowed, true)) {
                continue;
            }
            if ('' === ltrim($number, '0')) {
                continue; // APP-0 n'existe pas
            }
            $keys[] = strtoupper($project).'-'.ltrim($number, '0');
        }

        return array_values(array_unique($keys));
    }
}
