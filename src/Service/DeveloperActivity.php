<?php

namespace App\Service;

use App\Entity\Developer;

final class DeveloperActivity
{
    /**
     * @param IssueView[] $work      tickets dans un statut "de travail" pour le rôle du membre (ex. : en développement)
     * @param IssueView[] $otherWork autres tickets en cours (revue, test…)
     */
    public function __construct(
        public readonly Developer $developer,
        public readonly array $work,
        public readonly array $otherWork,
        public readonly int $wipLimit,
        public ?IssueView $lastActivity = null,
    ) {
    }

    /** Aucun ticket dans un statut de travail */
    public function isIdle(): bool
    {
        return [] === $this->work;
    }

    /** Membre sans ticket de travail alors qu'il devrait en avoir un */
    public function isIdleAlert(): bool
    {
        return $this->isIdle() && $this->developer->isExpectedOnTicket();
    }

    public function isOverWip(): bool
    {
        return \count($this->work) > $this->wipLimit;
    }

    public function getWorstLevel(): string
    {
        $worst = StatusAge::OK;
        foreach (array_merge($this->work, $this->otherWork) as $view) {
            if (StatusAge::severity($view->age->level) > StatusAge::severity($worst)) {
                $worst = $view->age->level;
            }
        }

        return $worst;
    }

    public function getCardClass(): string
    {
        return match (true) {
            $this->isIdleAlert() => 'border-danger',
            StatusAge::CRITICAL === $this->getWorstLevel() => 'border-danger',
            StatusAge::WARNING === $this->getWorstLevel(), $this->isOverWip() => 'border-warning',
            default => 'border-success',
        };
    }
}
