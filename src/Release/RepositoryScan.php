<?php

namespace App\Release;

/**
 * Lecture d'un dépôt GitLab : ce qui est dans la branche livrée et pas encore en production.
 */
final class RepositoryScan
{
    /**
     * Merge requests fusionnées dont le code est dans l'écart.
     *
     * @var array<int, array{iid: int, title: string, sourceBranch: string, author: string, mergedAt: ?\DateTimeImmutable, url: string, keys: string[], commits: int}>
     */
    public array $mergeRequests = [];

    /**
     * Commits poussés directement (hors merge request), commits de fusion exclus.
     *
     * @var array<int, array{sha: string, shortSha: string, title: string, message: string, author: string, date: ?\DateTimeImmutable, url: string, merge: bool, keys: string[]}>
     */
    public array $directCommits = [];

    public int $commitCount = 0;
    public ?string $error = null;
    public ?string $name = null;
    public ?string $webUrl = null;

    public function __construct(
        public readonly string $path,
        public ?string $reference,
        public readonly string $branch,
    ) {
    }

    public function getLabel(): string
    {
        return $this->name ?? $this->path;
    }

    /** Lien GitLab de comparaison production → branche livrée */
    public function getCompareUrl(): ?string
    {
        if (!$this->webUrl || null === $this->reference) {
            return null;
        }

        return sprintf('%s/-/compare/%s...%s', $this->webUrl, rawurlencode($this->reference), rawurlencode($this->branch));
    }

    /** @return string[] */
    public function getKeys(): array
    {
        $keys = [];
        foreach ($this->mergeRequests as $mr) {
            $keys = array_merge($keys, $mr['keys']);
        }
        foreach ($this->directCommits as $commit) {
            $keys = array_merge($keys, $commit['keys']);
        }

        return array_values(array_unique($keys));
    }
}
