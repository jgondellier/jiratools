<?php

namespace App\Service;

use App\Atlassian\AtlassianException;
use App\Entity\Project;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Jira\Sprint;

class SprintService
{
    /** @var array<string, array<int, Sprint[]>> état => board => sprints (mémorisés pour la requête en cours) */
    private array $sprintsByBoard = [];

    public function __construct(
        private readonly JiraClient $jira,
        private readonly IssueViewFactory $views,
        private readonly WorkingTime $workingTime,
    ) {
    }

    /**
     * Lit en parallèle les sprints actifs et futurs des boards de plusieurs projets
     * (évite un appel par projet dans les vues multi-projets).
     *
     * @param Project[] $projects
     * @param string[]  $states
     */
    public function prefetch(array $projects, array $states = ['active', 'future']): void
    {
        $boards = array_filter(array_map(fn (Project $p) => $p->getJiraBoardId(), $projects));
        if ([] === $boards) {
            return;
        }
        foreach ($this->jira->getSprintsMany($boards, $states) as $state => $byBoard) {
            $this->sprintsByBoard[$state] = $byBoard + ($this->sprintsByBoard[$state] ?? []);
        }
    }

    /** @return Sprint[] */
    public function getActiveSprints(Project $project): array
    {
        return $this->boardSprints($project, 'active');
    }

    public function getNextSprint(Project $project): ?Sprint
    {
        $future = $this->boardSprints($project, 'future');
        usort($future, fn (Sprint $a, Sprint $b) => ($a->startDate?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->startDate?->getTimestamp() ?? PHP_INT_MAX));

        return $future[0] ?? null;
    }

    /** @return Sprint[] derniers sprints clôturés, du plus récent au plus ancien */
    public function getClosedSprints(Project $project, int $limit = 6): array
    {
        $closed = $this->boardSprints($project, 'closed');
        usort($closed, fn (Sprint $a, Sprint $b) => [$b->completeDate ?? $b->endDate, $b->id] <=> [$a->completeDate ?? $a->endDate, $a->id]);

        return \array_slice($closed, 0, $limit);
    }

    /** @return Sprint[] tous les sprints (actifs, futurs, clôturés récents) pour les listes de choix */
    public function getSelectableSprints(Project $project): array
    {
        return array_merge($this->getActiveSprints($project), $this->getClosedSprints($project, 12));
    }

    public function getReport(Project $project, Sprint $sprint): SprintReport
    {
        return $this->getReports([[$project, $sprint]])[0];
    }

    /**
     * Rapports de plusieurs sprints, toutes les recherches Jira étant envoyées en parallèle.
     * L'historique des tickets (volumineux) n'est demandé que pour les tickets non terminés,
     * seuls concernés par les alertes de durée dans un statut.
     *
     * @param array<int, array{0: Project, 1: Sprint}> $items
     * @param array<string, array>                     $extraSearches autres recherches à envoyer dans le même lot
     * @param array<string, mixed>|null                $extraResults  résultats de ces recherches
     *
     * @return SprintReport[]
     */
    public function getReports(array $items, array $extraSearches = [], ?array &$extraResults = null): array
    {
        $searches = $extraSearches;
        foreach ($items as $i => [$project, $sprint]) {
            $base = sprintf('sprint = %d AND %s AND issuetype not in subTaskIssueTypes()', $sprint->id, ProjectScope::jql($project));
            $searches[$i.'_open'] = [$base.' AND statusCategory != Done ORDER BY Rank ASC', 500, true];
            $searches[$i.'_done'] = [$base.' AND statusCategory = Done ORDER BY Rank ASC', 500, false];
        }
        $results = $this->jira->searchMany($searches);
        $extraResults = array_intersect_key($results, $extraSearches);

        $reports = [];
        foreach ($items as $i => [$project, $sprint]) {
            foreach ([$results[$i.'_open'], $results[$i.'_done']] as $result) {
                if ($result instanceof AtlassianException) {
                    throw $result;
                }
            }
            $issues = array_merge($results[$i.'_open'], $results[$i.'_done']);

            $remaining = $sprint->endDate && $sprint->isActive() ? $this->workingTime->remainingDays($sprint->endDate) : null;
            $total = $sprint->startDate && $sprint->endDate ? $this->workingTime->remainingDays($sprint->endDate, $sprint->startDate) : null;

            $reports[$i] = new SprintReport(
                $project,
                $sprint,
                $this->views->createAll($issues, $project),
                $remaining,
                $total,
                $project->getJiraBoardId() ? $this->jira->boardUrl($project->getJiraBoardId(), $project->getJiraProjectKey()) : null,
            );
        }

        return $reports;
    }

    /**
     * Rapport d'un sprint et vélocité des derniers sprints du projet, en un seul lot de recherches.
     *
     * @return array{report: ?SprintReport, velocity: array}
     */
    public function getSprintOverview(Project $project, ?Sprint $sprint, int $limit = 6): array
    {
        $closed = $this->getClosedSprints($project, $limit);
        $extra = [] !== $closed ? ['_velocity' => [$this->velocityJql($project, $closed), 3000, false]] : [];
        $extraResults = [];
        $reports = $this->getReports($sprint ? [[$project, $sprint]] : [], $extra, $extraResults);

        $issues = $extraResults['_velocity'] ?? [];
        if ($issues instanceof AtlassianException) {
            throw $issues;
        }

        return ['report' => $reports[0] ?? null, 'velocity' => $this->computeVelocity($closed, $issues)];
    }

    /**
     * Vélocité des derniers sprints (tickets / points terminés), en une seule recherche Jira.
     *
     * @return array<int, array{sprint: Sprint, done: int, points: float, total: int}>
     */
    public function getVelocity(Project $project, int $limit = 6): array
    {
        $sprints = $this->getClosedSprints($project, $limit);

        return [] === $sprints ? [] : $this->computeVelocity($sprints, $this->jira->search($this->velocityJql($project, $sprints), 3000));
    }

    /** @param Sprint[] $sprints */
    private function velocityJql(Project $project, array $sprints): string
    {
        return sprintf(
            'sprint in (%s) AND %s AND issuetype not in subTaskIssueTypes()',
            implode(', ', array_map(fn (Sprint $s) => $s->id, $sprints)),
            ProjectScope::jql($project),
        );
    }

    /**
     * @param Sprint[] $sprints
     * @param Issue[]  $issues
     */
    private function computeVelocity(array $sprints, array $issues): array
    {
        $result = [];
        foreach ($sprints as $sprint) {
            // Un ticket reporté appartient à plusieurs sprints : il compte dans chacun
            $inSprint = array_filter($issues, fn (Issue $i) => \in_array($sprint->id, $i->sprintIds, true));
            $closedAt = $sprint->completeDate ?? $sprint->endDate;
            // Terminé avant la clôture du sprint (sinon le ticket a été terminé dans un sprint suivant)
            $done = array_filter($inSprint, fn (Issue $i) => $i->isDone() && (!$closedAt || !$i->resolved || $i->resolved <= $closedAt->modify('+1 hour')));
            $result[] = [
                'sprint' => $sprint,
                'done' => \count($done),
                'points' => array_sum(array_map(fn (Issue $i) => $i->storyPoints ?? 0, $done)),
                'total' => \count($inSprint),
            ];
        }

        return array_reverse($result);
    }

    /** @return Sprint[] */
    private function boardSprints(Project $project, string $state): array
    {
        $boardId = $project->getJiraBoardId();
        if (!$boardId) {
            return [];
        }
        $this->sprintsByBoard[$state][$boardId] ??= $this->jira->getSprints($boardId, $state);

        return $this->ownSprints($project, $this->sprintsByBoard[$state][$boardId]);
    }

    /**
     * Ne garde que les sprints créés sur le board du projet (un board peut afficher ceux d'autres boards).
     *
     * @param Sprint[] $sprints
     *
     * @return Sprint[]
     */
    private function ownSprints(Project $project, array $sprints): array
    {
        $own = array_filter($sprints, fn (Sprint $s) => null === $s->originBoardId || $s->originBoardId === $project->getJiraBoardId());

        return array_values([] !== $own ? $own : $sprints);
    }
}
