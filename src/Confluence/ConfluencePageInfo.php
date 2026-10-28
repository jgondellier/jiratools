<?php

namespace App\Confluence;

final class ConfluencePageInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $spaceKey,
        public readonly int $version,
        public readonly ?\DateTimeImmutable $lastUpdated,
        public readonly ?string $lastUpdatedBy,
    ) {
    }

    public function getAgeDays(): ?int
    {
        return $this->lastUpdated ? (int) $this->lastUpdated->diff(new \DateTimeImmutable())->days : null;
    }
}
