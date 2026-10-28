<?php

namespace App\Service;

use App\Entity\Project;
use App\Jira\Sprint;

final class SprintReport
{
    /** @var array<string, int> */
    public array $countByCategory = ['new' => 0, 'indeterminate' => 0, 'done' => 0];
    /** @var array<string, float> */
    public array $pointsByCategory = ['new' => 0.0, 'indeterminate' => 0.0, 'done' => 0.0];

    /**
     * @param IssueView[] $issues
     */
    public function __construct(
        public readonly Project $project,
        public readonly Sprint $sprint,
        public readonly array $issues,
        public readonly ?int $remainingDays,
        public readonly ?int $totalDays,
        public readonly ?string $boardUrl,
    ) {
        foreach ($issues as $view) {
            $cat = $view->issue->statusCategory;
            $this->countByCategory[$cat] = ($this->countByCategory[$cat] ?? 0) + 1;
            $this->pointsByCategory[$cat] = ($this->pointsByCategory[$cat] ?? 0) + ($view->issue->storyPoints ?? 0);
        }
    }

    public function getTotalCount(): int
    {
        return array_sum($this->countByCategory);
    }

    public function getTotalPoints(): float
    {
        return array_sum($this->pointsByCategory);
    }

    public function usesPoints(): bool
    {
        return $this->getTotalPoints() > 0;
    }

    /** Avancement du temps écoulé (0-100) */
    public function getTimeProgress(): ?int
    {
        $start = $this->sprint->startDate;
        $end = $this->sprint->endDate;
        if (!$start || !$end || $end <= $start) {
            return null;
        }
        $ratio = (time() - $start->getTimestamp()) / ($end->getTimestamp() - $start->getTimestamp());

        return (int) round(max(0, min(1, $ratio)) * 100);
    }

    /** Avancement du travail terminé (0-100), en points si disponibles sinon en nombre de tickets */
    public function getWorkProgress(): int
    {
        if ($this->usesPoints()) {
            return (int) round($this->pointsByCategory['done'] / $this->getTotalPoints() * 100);
        }
        $total = $this->getTotalCount();

        return $total > 0 ? (int) round($this->countByCategory['done'] / $total * 100) : 0;
    }

    /** ok / warning / danger selon l'écart entre temps écoulé et travail terminé */
    public function getHealth(): string
    {
        $time = $this->getTimeProgress();
        if (null === $time) {
            return 'secondary';
        }
        $gap = $time - $this->getWorkProgress();

        return match (true) {
            $gap > 30 => 'danger',
            $gap > 15 => 'warning',
            default => 'success',
        };
    }

    public function getAlertCount(): int
    {
        return \count(array_filter($this->issues, fn (IssueView $v) => $v->age->isAlert()));
    }

    /** @return IssueView[] */
    public function getUnestimated(): array
    {
        return array_values(array_filter($this->issues, fn (IssueView $v) => null === $v->issue->storyPoints && !$v->issue->isDone()));
    }

    /** @return IssueView[] */
    public function getFlagged(): array
    {
        return array_values(array_filter($this->issues, fn (IssueView $v) => $v->issue->flagged && !$v->issue->isDone()));
    }

    /**
     * Charge restante par personne assignée.
     *
     * @return array<string, array{name: string, avatar: ?string, todo: int, inProgress: int, done: int, remainingPoints: float}>
     */
    public function getLoadByAssignee(): array
    {
        $load = [];
        foreach ($this->issues as $view) {
            $i = $view->issue;
            $key = $i->assigneeId ?? '_';
            $load[$key] ??= ['name' => $i->assigneeName ?? 'Non assigné', 'avatar' => $i->assigneeAvatar, 'todo' => 0, 'inProgress' => 0, 'done' => 0, 'remainingPoints' => 0.0];
            match ($i->statusCategory) {
                'done' => $load[$key]['done']++,
                'indeterminate' => $load[$key]['inProgress']++,
                default => $load[$key]['todo']++,
            };
            if (!$i->isDone()) {
                $load[$key]['remainingPoints'] += $i->storyPoints ?? 0;
            }
        }
        uasort($load, fn ($a, $b) => [$b['remainingPoints'], $b['todo'] + $b['inProgress']] <=> [$a['remainingPoints'], $a['todo'] + $a['inProgress']]);

        return $load;
    }

    /**
     * Tickets groupés par statut (dans l'ordre : à faire, en cours, terminé).
     *
     * @return array<string, IssueView[]>
     */
    public function getIssuesByStatus(): array
    {
        $order = ['new' => 0, 'indeterminate' => 1, 'done' => 2];
        $issues = $this->issues;
        usort($issues, fn (IssueView $a, IssueView $b) => ($order[$a->issue->statusCategory] ?? 0) <=> ($order[$b->issue->statusCategory] ?? 0));
        $grouped = [];
        foreach ($issues as $view) {
            $grouped[$view->issue->status][] = $view;
        }

        return $grouped;
    }
}
