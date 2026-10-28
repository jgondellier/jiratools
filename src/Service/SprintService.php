<?php

namespace App\Service;

use App\Entity\Project;
use App\Jira\JiraClient;
use App\Jira\Sprint;

class SprintService
{
    public function __construct(
        private readonly JiraClient $jira,
        private readonly IssueViewFactory $views,
        private readonly WorkingTime $workingTime,
    ) {
    }

    /** @return Sprint[] */
    public function getActiveSprints(Project $project): array
    {
        if (!$project->getJiraBoardId()) {
            return [];
        }

        return $this->ownSprints($project, $this->jira->getSprints($project->getJiraBoardId(), 'active'));
    }

    public function getNextSprint(Project $project): ?Sprint
    {
        if (!$project->getJiraBoardId()) {
            return null;
        }
        $future = $this->ownSprints($project, $this->jira->getSprints($project->getJiraBoardId(), 'future'));
        usort($future, fn (Sprint $a, Sprint $b) => ($a->startDate?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->startDate?->getTimestamp() ?? PHP_INT_MAX));

        return $future[0] ?? null;
    }

    /** @return Sprint[] derniers sprints clôturés, du plus récent au plus ancien */
    public function getClosedSprints(Project $project, int $limit = 6): array
    {
        if (!$project->getJiraBoardId()) {
            return [];
        }
        $closed = $this->ownSprints($project, $this->jira->getSprints($project->getJiraBoardId(), 'closed'));
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
        $issues = $this->jira->search(
            sprintf('sprint = %d AND %s AND issuetype not in subTaskIssueTypes() ORDER BY Rank ASC', $sprint->id, ProjectScope::jql($project)),
            500,
            true,
        );

        $remaining = $sprint->endDate && $sprint->isActive() ? $this->workingTime->remainingDays($sprint->endDate) : null;
        $total = $sprint->startDate && $sprint->endDate ? $this->workingTime->remainingDays($sprint->endDate, $sprint->startDate) : null;

        return new SprintReport(
            $project,
            $sprint,
            $this->views->createAll($issues, $project),
            $remaining,
            $total,
            $project->getJiraBoardId() ? $this->jira->boardUrl($project->getJiraBoardId(), $project->getJiraProjectKey()) : null,
        );
    }

    /**
     * Vélocité des derniers sprints (tickets / points terminés).
     *
     * @return array<int, array{sprint: Sprint, done: int, points: float, total: int}>
     */
    public function getVelocity(Project $project, int $limit = 6): array
    {
        $result = [];
        foreach ($this->getClosedSprints($project, $limit) as $sprint) {
            $issues = $this->jira->search(
                sprintf('sprint = %d AND %s AND issuetype not in subTaskIssueTypes()', $sprint->id, ProjectScope::jql($project)),
                500,
            );
            $done = array_filter($issues, function ($i) use ($sprint) {
                // Terminé avant la clôture du sprint (sinon le ticket a été reporté)
                $closedAt = $sprint->completeDate ?? $sprint->endDate;

                return $i->isDone() && (!$closedAt || !$i->resolved || $i->resolved <= $closedAt->modify('+1 hour'));
            });
            $result[] = [
                'sprint' => $sprint,
                'done' => \count($done),
                'points' => array_sum(array_map(fn ($i) => $i->storyPoints ?? 0, $done)),
                'total' => \count($issues),
            ];
        }

        return array_reverse($result);
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
