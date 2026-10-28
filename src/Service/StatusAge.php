<?php

namespace App\Service;

final class StatusAge
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    public function __construct(
        public readonly float $days,
        public readonly string $level,
        public readonly ?float $warningDays,
        public readonly ?float $criticalDays,
    ) {
    }

    public function isAlert(): bool
    {
        return self::OK !== $this->level;
    }

    public function getBadgeClass(): string
    {
        return match ($this->level) {
            self::CRITICAL => 'text-bg-danger',
            self::WARNING => 'text-bg-warning',
            default => 'text-bg-light border',
        };
    }

    public function getLabel(): string
    {
        if ($this->days < 1) {
            $hours = (int) round($this->days * 24);

            return $hours < 1 ? '< 1 h' : $hours.' h';
        }

        return str_replace('.', ',', (string) round($this->days, 1)).' j';
    }

    public static function severity(string $level): int
    {
        return match ($level) {
            self::CRITICAL => 2,
            self::WARNING => 1,
            default => 0,
        };
    }
}
