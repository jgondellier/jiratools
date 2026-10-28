<?php

namespace App\Service;

use App\Entity\Developer;

final class DeveloperActivity
{
    /**
     * @param IssueView[] $inProgress
     */
    public function __construct(
        public readonly Developer $developer,
        public readonly array $inProgress,
        public readonly int $wipLimit,
        public ?IssueView $lastActivity = null,
    ) {
    }

    public function isIdle(): bool
    {
        return [] === $this->inProgress;
    }

    /** Membre sans ticket alors qu'il devrait en avoir un */
    public function isIdleAlert(): bool
    {
        return $this->isIdle() && $this->developer->isExpectedOnTicket();
    }

    public function isOverWip(): bool
    {
        return \count($this->inProgress) > $this->wipLimit;
    }

    public function getWorstLevel(): string
    {
        $worst = StatusAge::OK;
        foreach ($this->inProgress as $view) {
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
