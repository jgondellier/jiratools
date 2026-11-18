<?php

namespace App\Release;

use App\Jira\Issue;

/**
 * Contenu d'une MEP : dépôts lus, tickets cités dans le code, écarts avec Jira.
 */
final class ReleaseContent
{
    /** @var RepositoryScan[] */
    public array $repositories = [];

    /** @var array<string, ReleaseTicket> clé => ticket cité dans le code */
    public array $tickets = [];

    /**
     * Tickets des versions de la MEP sans aucune merge request ni commit.
     *
     * @var Issue[]
     */
    public array $missing = [];

    /**
     * Merge requests et commits directs sans clé de ticket.
     *
     * @var array<int, array{repository: string, type: string, label: string, detail: ?string, url: string, author: string, date: ?\DateTimeImmutable}>
     */
    public array $untracked = [];

    public ?string $versionJql = null;

    /**
     * @param string[]              $projectKeys    clés de projets Jira reconnues dans les commits
     * @param array<int, array>     $candidates     versions non livrées de mes projets
     * @param array<string, string> $targetVersions identifiant => nom des versions de la MEP
     */
    public function __construct(
        public readonly array $projectKeys,
        public readonly array $candidates,
        public readonly array $targetVersions,
    ) {
    }

    /** @return array{error: int, warning: int, ok: int} */
    public function getCounts(): array
    {
        $counts = [ReleaseTicket::ERROR => 0, ReleaseTicket::WARNING => 0, ReleaseTicket::OK => 0];
        foreach ($this->tickets as $ticket) {
            ++$counts[$ticket->getLevel()];
        }

        return $counts;
    }

    /** @return RepositoryScan[] */
    public function getRepositoryErrors(): array
    {
        return array_values(array_filter($this->repositories, fn (RepositoryScan $r) => null !== $r->error));
    }

    /** Clés des tickets à corriger dans Jira (pour une recherche groupée) */
    public function getKeysToFix(): array
    {
        return array_values(array_map(
            fn (ReleaseTicket $t) => $t->issue->key,
            array_filter($this->tickets, fn (ReleaseTicket $t) => null !== $t->issue && ReleaseTicket::OK !== $t->getLevel()),
        ));
    }
}
