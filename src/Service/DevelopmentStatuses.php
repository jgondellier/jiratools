<?php

namespace App\Service;

use App\Jira\Issue;
use App\Repository\SettingRepository;

/**
 * Statuts Jira considérés comme "en cours de développement" pour le tableau "Qui fait quoi".
 * Si aucun statut n'est paramétré, tous les statuts de la catégorie Jira "En cours" sont pris en compte.
 */
class DevelopmentStatuses
{
    public const SETTING = 'development_statuses';

    /** @var string[]|null */
    private ?array $statuses = null;

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    /** @return string[] */
    public function getStatuses(): array
    {
        return $this->statuses ??= array_values(array_filter((array) $this->settings->getValue(self::SETTING, []), 'is_string'));
    }

    /** @param string[] $statuses */
    public function setStatuses(array $statuses): void
    {
        $clean = [];
        foreach ($statuses as $status) {
            $status = trim((string) $status);
            if ('' !== $status && !\in_array(mb_strtolower($status), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $status;
            }
        }
        $this->settings->setValue(self::SETTING, $clean);
        $this->statuses = $clean;
    }

    public function isConfigured(): bool
    {
        return [] !== $this->getStatuses();
    }

    public function isDevelopment(Issue $issue): bool
    {
        if (!$this->isConfigured()) {
            return $issue->isInProgress();
        }

        return \in_array(mb_strtolower($issue->status), array_map('mb_strtolower', $this->getStatuses()), true);
    }
}
