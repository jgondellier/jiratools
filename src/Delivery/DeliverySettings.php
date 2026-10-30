<?php

namespace App\Delivery;

use App\Repository\SettingRepository;

/**
 * Paramétrage du contrôle des livraisons (stocké en base, modifiable dans l'interface).
 */
class DeliverySettings
{
    public const SETTING = 'deliveries';

    private const DEFAULTS = [
        'wsProjects' => [],          // [{key: "WSC", prefix: "WS-Commande"}]
        'wsLabels' => [],            // étiquettes qui identifient mes tickets WS dans ces projets
        'myComponents' => [],        // [{key: "APP", prefix: "PORTAIL"}] nom de mes projets dans le calendrier
        'pageId' => null,            // page Confluence du calendrier des livraisons
        'dateColumn' => 'Date',
        'versionColumn' => 'Version',
        'marginDays' => 0,           // la livraison WS doit précéder ma version d'au moins N jours
        'horizonDays' => 45,         // prochaines livraisons : versions livrées dans les N jours (calendaires)
        'startDays' => 5,            // un ticket non commencé à moins de N jours ouvrés de la livraison est en alerte
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
        $clean['marginDays'] = max(0, (int) $clean['marginDays']);
        $clean['horizonDays'] = max(1, (int) $clean['horizonDays']);
        $clean['startDays'] = max(0, (int) $clean['startDays']);
        $clean['pageId'] = self::extractPageId((string) $clean['pageId']);
        $clean['wsLabels'] = self::parseLabels(\is_array($clean['wsLabels']) ? implode(',', $clean['wsLabels']) : (string) $clean['wsLabels']);
        $this->settings->setValue(self::SETTING, $clean);
        $this->values = $clean;
    }

    /** Page du calendrier ou projets WS renseignés (les projets WS peuvent aussi venir du board) */
    public function isConfigured(): bool
    {
        return null !== $this->getPageId() || [] !== $this->getWsProjects();
    }

    /** @return array<int, array{key: string, prefix: ?string}> */
    public function getWsProjects(): array
    {
        return (array) $this->all()['wsProjects'];
    }

    /** @return string[] */
    public function getWsKeys(): array
    {
        return array_values(array_unique(array_map(fn (array $p) => $p['key'], $this->getWsProjects())));
    }

    /** @return string[] */
    public function getWsLabels(): array
    {
        return array_values(array_filter((array) $this->all()['wsLabels'], 'is_string'));
    }

    /** Le ticket porte-t-il une des étiquettes WS (sans tenir compte de la casse) ? */
    public function hasWsLabel(array $labels): bool
    {
        $wanted = array_map('mb_strtolower', $this->getWsLabels());

        return [] !== array_intersect($wanted, array_map('mb_strtolower', $labels));
    }

    /**
     * "ws-mon-equipe, ws-autre" => ["ws-mon-equipe", "ws-autre"] (une étiquette Jira ne contient pas d'espace)
     *
     * @return string[]
     */
    public static function parseLabels(string $text): array
    {
        $labels = array_filter(array_map('trim', preg_split('/[\s,;]+/', $text) ?: []));

        return array_values(array_unique($labels));
    }

    /** @return array<int, array{key: string, prefix: ?string}> */
    public function getMyComponents(): array
    {
        return (array) $this->all()['myComponents'];
    }

    public function getPrefix(string $projectKey): ?string
    {
        foreach (array_merge($this->getWsProjects(), $this->getMyComponents()) as $project) {
            if ($project['key'] === $projectKey) {
                return $project['prefix'] ?: null;
            }
        }

        return null;
    }

    public function getPageId(): ?string
    {
        return $this->all()['pageId'] ?: null;
    }

    public function getDateColumn(): string
    {
        return (string) $this->all()['dateColumn'] ?: 'Date';
    }

    public function getVersionColumn(): string
    {
        return (string) $this->all()['versionColumn'] ?: 'Version';
    }

    public function getHorizonDays(): int
    {
        return (int) $this->all()['horizonDays'];
    }

    public function getStartDays(): int
    {
        return (int) $this->all()['startDays'];
    }

    public function getMarginDays(): int
    {
        return (int) $this->all()['marginDays'];
    }

    /**
     * "WSC = WS-Commande" (une ligne par projet) => [{key: WSC, prefix: WS-Commande}]
     *
     * @return array<int, array{key: string, prefix: ?string}>
     */
    public static function parseProjects(string $text): array
    {
        $projects = [];
        foreach (preg_split('/[\r\n;]+/', $text) ?: [] as $line) {
            [$key, $prefix] = array_map('trim', explode('=', $line, 2)) + [1 => ''];
            $key = strtoupper($key);
            if (preg_match('/^[A-Z][A-Z0-9_]+$/', $key)) {
                $projects[] = ['key' => $key, 'prefix' => '' !== $prefix ? $prefix : null];
            }
        }

        return $projects;
    }

    public static function formatProjects(array $projects): string
    {
        return implode("\n", array_map(fn (array $p) => $p['key'].($p['prefix'] ? ' = '.$p['prefix'] : ''), $projects));
    }

    /** Accepte un identifiant ou une URL de page (…/pages/123456/… ou ?pageId=123456) */
    public static function extractPageId(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#/pages/(\d+)#', $value, $m) || preg_match('#[?&]pageId=(\d+)#', $value, $m) || preg_match('/^(\d+)$/', $value, $m)) {
            return $m[1];
        }

        return null;
    }
}
