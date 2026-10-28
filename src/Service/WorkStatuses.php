<?php

namespace App\Service;

use App\Entity\Developer;
use App\Jira\Issue;
use App\Repository\SettingRepository;

/**
 * Statuts Jira qui signifient "le membre travaille sur ce ticket", par rôle
 * (ex. : "En développement" pour un développeur, "En test" pour un testeur).
 *
 * Résolution : statuts du rôle > statuts par défaut > catégorie Jira "En cours".
 */
class WorkStatuses
{
    public const SETTING = 'work_statuses';
    public const DEFAULT = 'default';

    /** Ancien réglage (un seul jeu de statuts), repris comme valeur par défaut */
    private const LEGACY_SETTING = 'development_statuses';

    /** @var array<string, string[]>|null */
    private ?array $config = null;

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    /**
     * @return array<string, string[]> clé = "default" ou code de rôle
     */
    public function getConfig(): array
    {
        if (null === $this->config) {
            $raw = $this->settings->getValue(self::SETTING);
            if (!\is_array($raw)) {
                $raw = [self::DEFAULT => (array) $this->settings->getValue(self::LEGACY_SETTING, [])];
            }
            $this->config = [];
            foreach ($raw as $key => $statuses) {
                $clean = self::clean((array) $statuses);
                if ([] !== $clean) {
                    $this->config[(string) $key] = $clean;
                }
            }
            // Le réglage par défaut en premier
            uksort($this->config, fn ($a, $b) => (int) (self::DEFAULT !== $a) <=> (int) (self::DEFAULT !== $b));
        }

        return $this->config;
    }

    /**
     * @param array<string, string[]> $config
     */
    public function setConfig(array $config): void
    {
        $clean = [];
        foreach ($config as $key => $statuses) {
            $statuses = self::clean((array) $statuses);
            if ([] !== $statuses && (self::DEFAULT === $key || \in_array($key, Developer::ROLES, true))) {
                $clean[$key] = $statuses;
            }
        }
        $this->settings->setValue(self::SETTING, $clean);
        $this->config = $clean;
    }

    /**
     * Statuts applicables à un rôle ([] = catégorie Jira "En cours").
     *
     * @return string[]
     */
    public function getStatuses(?string $role = null): array
    {
        $config = $this->getConfig();

        return $config[$role ?? self::DEFAULT] ?? $config[self::DEFAULT] ?? [];
    }

    /** Le rôle a-t-il ses propres statuts (sinon il hérite du réglage par défaut) ? */
    public function hasOwnStatuses(string $role): bool
    {
        return isset($this->getConfig()[$role]);
    }

    /**
     * Tous les statuts paramétrés, tous rôles confondus (pour la requête Jira).
     *
     * @return string[]
     */
    public function getAllStatuses(): array
    {
        return self::clean(array_merge(...array_values($this->getConfig()) ?: [[]]));
    }

    public function isWork(Issue $issue, ?string $role = null): bool
    {
        $statuses = $this->getStatuses($role);
        if ([] === $statuses) {
            return $issue->isInProgress();
        }

        return \in_array(mb_strtolower($issue->status), array_map('mb_strtolower', $statuses), true);
    }

    /**
     * Supprime les vides et doublons (insensible à la casse).
     *
     * @return string[]
     */
    private static function clean(array $statuses): array
    {
        $clean = [];
        foreach ($statuses as $status) {
            $status = trim((string) $status);
            if ('' !== $status && !\in_array(mb_strtolower($status), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $status;
            }
        }

        return $clean;
    }
}
