<?php

namespace App\Jira;

/**
 * Représentation simplifiée d'un ticket Jira.
 */
final class Issue
{
    public const CATEGORY_TODO = 'new';
    public const CATEGORY_IN_PROGRESS = 'indeterminate';
    public const CATEGORY_DONE = 'done';

    /**
     * @param string[] $components
     * @param string[] $labels
     * @param string[] $fixVersions
     * @param string[] $sprints
     * @param int[]    $sprintIds
     * @param array<int, array{name: string, releaseDate: ?\DateTimeImmutable, released: bool}> $versionDetails
     * @param array<int, array{key: string, summary: string, status: string, statusCategory: string, projectKey: string, type: string}> $links
     */
    public function __construct(
        public readonly string $key,
        public readonly string $summary,
        public readonly string $url,
        public readonly string $projectKey,
        public readonly string $status,
        public readonly string $statusCategory,
        public readonly string $type,
        public readonly ?string $typeIcon,
        public readonly ?string $priority,
        public readonly ?string $priorityIcon,
        public readonly ?string $assigneeId,
        public readonly ?string $assigneeName,
        public readonly ?string $assigneeAvatar,
        public readonly array $components,
        public readonly array $labels,
        public readonly array $fixVersions,
        public readonly array $sprints,
        public readonly ?float $storyPoints,
        public readonly bool $flagged,
        public readonly ?\DateTimeImmutable $created,
        public readonly ?\DateTimeImmutable $updated,
        public readonly ?\DateTimeImmutable $resolved,
        public readonly ?\DateTimeImmutable $statusSince,
        public readonly ?string $parentKey = null,
        public readonly ?string $parentSummary = null,
        public readonly array $sprintIds = [],
        public readonly array $versionDetails = [],
        public readonly array $links = [],
    ) {
    }

    public function isTodo(): bool
    {
        return self::CATEGORY_TODO === $this->statusCategory;
    }

    public function isInProgress(): bool
    {
        return self::CATEGORY_IN_PROGRESS === $this->statusCategory;
    }

    public function isDone(): bool
    {
        return self::CATEGORY_DONE === $this->statusCategory;
    }

    public function isBug(): bool
    {
        return \in_array(mb_strtolower($this->type), ['bug', 'anomalie', 'bogue', 'defect'], true);
    }

    /** Classe CSS Bootstrap du badge de statut */
    public function getStatusBadgeClass(): string
    {
        return match ($this->statusCategory) {
            self::CATEGORY_DONE => 'text-bg-success',
            self::CATEGORY_IN_PROGRESS => 'text-bg-primary',
            default => 'text-bg-secondary',
        };
    }
}
