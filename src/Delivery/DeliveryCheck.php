<?php

namespace App\Delivery;

use App\Jira\Issue;

/**
 * Contrôle d'un couple "mon ticket" / "ticket web service lié".
 */
final class DeliveryCheck
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @var array<int, array{level: string, message: string}> */
    public array $problems = [];

    /**
     * @param array<int, array{name: string, jiraDate: ?\DateTimeImmutable, confluenceDate: ?\DateTimeImmutable, inConfluence: bool, otherComponents: string[]}> $wsVersions
     */
    public function __construct(
        public readonly Issue $issue,
        public readonly ?\DateTimeImmutable $myDate,
        public readonly ?string $myVersion,
        public readonly Issue $wsIssue,
        public readonly string $linkType,
        public readonly array $wsVersions,
        public readonly ?\DateTimeImmutable $wsDate,
    ) {
    }

    public function add(string $level, string $message): void
    {
        $this->problems[] = ['level' => $level, 'message' => $message];
    }

    public function getLevel(): string
    {
        $levels = array_column($this->problems, 'level');

        return match (true) {
            \in_array(self::ERROR, $levels, true) => self::ERROR,
            \in_array(self::WARNING, $levels, true) => self::WARNING,
            default => self::OK,
        };
    }

    /** Écart en jours entre la livraison WS et ma version (positif = WS livré avant) */
    public function getMarginDays(): ?int
    {
        if (!$this->myDate || !$this->wsDate) {
            return null;
        }

        return (int) $this->wsDate->diff($this->myDate)->format('%r%a');
    }
}
