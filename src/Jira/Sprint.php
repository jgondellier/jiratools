<?php

namespace App\Jira;

final class Sprint
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $state,
        public readonly ?\DateTimeImmutable $startDate,
        public readonly ?\DateTimeImmutable $endDate,
        public readonly ?\DateTimeImmutable $completeDate,
        public readonly ?string $goal,
        public readonly ?int $originBoardId,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: (string) ($data['name'] ?? 'Sprint '.$data['id']),
            state: (string) ($data['state'] ?? 'future'),
            startDate: JiraClient::date($data['startDate'] ?? null),
            endDate: JiraClient::date($data['endDate'] ?? null),
            completeDate: JiraClient::date($data['completeDate'] ?? null),
            goal: ($data['goal'] ?? '') !== '' ? (string) $data['goal'] : null,
            originBoardId: isset($data['originBoardId']) ? (int) $data['originBoardId'] : null,
        );
    }

    public function isActive(): bool
    {
        return 'active' === $this->state;
    }

    public function isClosed(): bool
    {
        return 'closed' === $this->state;
    }
}
