<?php

namespace App\Delivery;

use App\Jira\JiraClient;

/**
 * Périmètre des livraisons : mes projets, projets web services et étiquette de mes tickets WS.
 * Déduit du filtre des boards de mes projets (ex. project = APP OR (project = WS AND labels = mon-equipe)),
 * complété par le paramétrage.
 */
final class DeliveryScope
{
    /**
     * @param string[] $myKeys   clés Jira de mes projets
     * @param string[] $wsKeys   clés Jira des projets web services
     * @param string[] $labels   étiquettes de mes tickets WS
     * @param string   $myJql    périmètre de mes projets (composants inclus)
     * @param array<int, array{id: int, name: string, jql: string, projects: string[]}> $boards boards lus
     * @param string[] $errors   boards illisibles
     */
    public function __construct(
        public readonly array $myKeys,
        public readonly array $wsKeys,
        public readonly array $labels,
        public readonly string $myJql,
        public readonly array $boards = [],
        public readonly array $errors = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->myKeys;
    }

    public function isWs(string $projectKey): bool
    {
        return \in_array($projectKey, $this->wsKeys, true);
    }

    /** Le ticket porte-t-il une des étiquettes WS (sans tenir compte de la casse) ? */
    public function hasWsLabel(array $labels): bool
    {
        return [] !== array_intersect(array_map('mb_strtolower', $this->labels), array_map('mb_strtolower', $labels));
    }

    /** Filtres des boards réunis, ou null si aucun board n'a pu être lu */
    private function boardsJql(): ?string
    {
        $jqls = array_filter(array_map(fn (array $b) => $b['jql'], $this->boards));

        return [] === $jqls ? null : '(('.implode(') OR (', $jqls).'))';
    }

    /**
     * Tickets de mes projets et tickets WS suivis : filtre des boards si disponible (mes projets restreints
     * à leurs composants), sinon mes projets + tickets WS portant l'étiquette.
     */
    public function issuesJql(): string
    {
        $ws = [] !== $this->wsKeys ? 'project in ('.JiraClient::quoteList($this->wsKeys).')' : null;
        // myJql est déjà entre parenthèses (ProjectScope::jqlForAll)
        if ($boards = $this->boardsJql()) {
            return sprintf('%s AND (%s%s)', $boards, $this->myJql, $ws ? " OR $ws" : '');
        }
        if (!$ws) {
            return "($this->myJql)";
        }
        $labels = [] !== $this->labels ? ' AND labels in ('.JiraClient::quoteList($this->labels).')' : '';

        return sprintf('(%s OR (%s%s))', $this->myJql, $ws, $labels);
    }

    /**
     * Tickets WS suivis (ceux du board, ou portant l'étiquette) ; null si on ne sait pas les distinguer.
     */
    public function wsTrackedJql(): ?string
    {
        if ([] === $this->wsKeys) {
            return null;
        }
        $ws = 'project in ('.JiraClient::quoteList($this->wsKeys).')';
        if ($boards = $this->boardsJql()) {
            return "$boards AND $ws";
        }

        return [] !== $this->labels ? "$ws AND labels in (".JiraClient::quoteList($this->labels).')' : null;
    }
}
