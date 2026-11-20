<?php

namespace App\Release;

use App\Entity\Project;

/**
 * Suivi du code d'un projet : branche develop, derniers tags de version, et tickets Jira correspondants.
 */
final class ProjectCodeReport
{
    public ?string $error = null;
    public ?string $repositoryName = null;
    public ?string $repositoryUrl = null;
    public ?RepositoryScan $develop = null;

    /**
     * Tags lus, du plus récent au plus ancien, avec leur contenu et les versions Jira de même numéro.
     *
     * @var array<int, array{name: string, date: ?\DateTimeImmutable, url: string, previous: ?string, scan: RepositoryScan, versions: array<int, array>, problems: array<int, array{level: string, message: string}>}>
     */
    public array $tags = [];

    /** @var array<string, TrackedTicket> */
    public array $tickets = [];

    public function __construct(
        public readonly Project $project,
        public readonly string $branch,
    ) {
    }

    /** @return string[] */
    public function getTagNames(): array
    {
        return array_column($this->tags, 'name');
    }

    /** @return array{error: int, warning: int, ok: int} */
    public function getCounts(): array
    {
        $counts = [TrackedTicket::ERROR => 0, TrackedTicket::WARNING => 0, TrackedTicket::OK => 0];
        foreach ($this->tickets as $ticket) {
            ++$counts[$ticket->getLevel()];
        }

        return $counts;
    }

    /** Types de tickets présents (filtre de l'écran) */
    public function getTypes(): array
    {
        $types = array_unique(array_filter(array_map(fn (TrackedTicket $t) => $t->issue?->type, $this->tickets)));
        sort($types);

        return array_values($types);
    }

    /** Nombre de merge requests et commits sans ticket, tous intervalles confondus */
    public function countUntracked(): int
    {
        $count = 0;
        foreach (array_merge([$this->develop], array_column($this->tags, 'scan')) as $scan) {
            if (null === $scan) {
                continue;
            }
            $count += \count(array_filter($scan->mergeRequests, fn ($mr) => [] === $mr['keys']));
            $count += \count(array_filter($scan->directCommits, fn ($c) => [] === $c['keys']));
        }

        return $count;
    }
}
