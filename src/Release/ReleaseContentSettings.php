<?php

namespace App\Release;

use App\Repository\SettingRepository;

/**
 * Paramétrage du contenu de livraison (MEP) lu dans GitLab, stocké en base.
 */
class ReleaseContentSettings
{
    public const SETTING = 'release_content';

    private const DEFAULTS = [
        'repositories' => [],      // [{path: "groupe/projet", reference: ?string, branch: ?string}]
        'branch' => 'develop',     // branche livrée lors d'une MEP
        'reference' => 'master',   // branche ou tag de production ; vide = dernier tag
        'ticketProjects' => [],    // clés de projets Jira reconnues dans les commits ; vide = mes projets + projets WS
        'expectedStatuses' => [],  // statuts attendus pour un ticket livré ; vide = catégorie "Terminé"
        'tagCount' => 10,          // suivi par projet : nombre de tags de version lus
    ];

    private ?array $values = null;

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function all(): array
    {
        return $this->values ??= array_replace(self::DEFAULTS, (array) $this->settings->getValue(self::SETTING, []));
    }

    public function save(array $values): void
    {
        $clean = array_replace(self::DEFAULTS, array_intersect_key($values, self::DEFAULTS));
        $clean['branch'] = trim((string) $clean['branch']) ?: 'develop';
        $clean['reference'] = trim((string) $clean['reference']);
        $clean['tagCount'] = max(1, min(50, (int) $clean['tagCount']));
        $this->settings->setValue(self::SETTING, $clean);
        $this->values = $clean;
    }

    public function isConfigured(): bool
    {
        return [] !== $this->getRepositories();
    }

    /** @return array<int, array{path: string, reference: ?string, branch: ?string}> */
    public function getRepositories(): array
    {
        return (array) $this->all()['repositories'];
    }

    public function getBranch(): string
    {
        return (string) $this->all()['branch'];
    }

    /** Branche ou tag de production ; chaîne vide = dernier tag du dépôt */
    public function getReference(): string
    {
        return (string) $this->all()['reference'];
    }

    /** Suivi par projet : nombre de tags de version lus (les plus récents) */
    public function getTagCount(): int
    {
        return (int) $this->all()['tagCount'];
    }

    /** @return string[] */
    public function getTicketProjects(): array
    {
        return array_values(array_filter((array) $this->all()['ticketProjects'], 'is_string'));
    }

    /** @return string[] */
    public function getExpectedStatuses(): array
    {
        return array_values(array_filter((array) $this->all()['expectedStatuses'], 'is_string'));
    }

    /**
     * Une ligne par dépôt : "groupe/projet", éventuellement suivi de "| référence | branche"
     * pour remplacer les valeurs communes. L'URL du dépôt est acceptée.
     *
     * @return array<int, array{path: string, reference: ?string, branch: ?string}>
     */
    public static function parseRepositories(string $text): array
    {
        $repositories = [];
        foreach (preg_split('/[\r\n]+/', $text) ?: [] as $line) {
            [$path, $reference, $branch] = array_map('trim', explode('|', $line, 3)) + [1 => '', 2 => ''];
            // https://gitlab.entreprise.fr/groupe/projet(.git) ou git@gitlab.entreprise.fr:groupe/projet.git
            $path = preg_replace(['#^[a-z]+://[^/]+/#i', '#^[^@\s]+@[^:]+:#', '#\.git$#', '#/-/.*$#'], '', $path) ?? '';
            $path = trim($path, '/ ');
            if ('' === $path || !preg_match('#^[\w.\-]+(/[\w.\-]+)*$#', $path)) {
                continue;
            }
            $repositories[] = ['path' => $path, 'reference' => '' !== $reference ? $reference : null, 'branch' => '' !== $branch ? $branch : null];
        }

        return $repositories;
    }

    public static function formatRepositories(array $repositories): string
    {
        return implode("\n", array_map(function (array $r) {
            if (null === $r['reference'] && null === $r['branch']) {
                return $r['path'];
            }

            return rtrim(sprintf('%s | %s | %s', $r['path'], $r['reference'] ?? '', $r['branch'] ?? ''), ' |');
        }, $repositories));
    }

    /** "APP, ws ; REF" => ["APP", "WS", "REF"] */
    public static function parseProjectKeys(string $text): array
    {
        $keys = array_map('strtoupper', array_filter(array_map('trim', preg_split('/[\s,;]+/', $text) ?: [])));

        return array_values(array_unique(array_filter($keys, fn (string $k) => (bool) preg_match('/^[A-Z][A-Z0-9_]+$/', $k))));
    }

    /** "Recette, Validé" (une valeur par ligne ou séparées par des virgules) */
    public static function parseList(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $text) ?: []))));
    }
}
