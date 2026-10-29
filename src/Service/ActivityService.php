<?php

namespace App\Service;

use App\Atlassian\AtlassianException;
use App\Entity\Developer;
use App\Entity\Team;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;

/**
 * "Qui fait quoi" : ticket(s) de travail de chaque membre (selon son rôle), membres sans ticket.
 */
class ActivityService
{
    /** @var IssueView[]|null tickets en cours non assignés, lus avec le travail des membres */
    private ?array $unassigned = null;

    /** @var Issue[]|null tickets des membres mis à jour récemment, lus avec le travail des membres */
    private ?array $recent = null;

    public function __construct(
        private readonly JiraClient $jira,
        private readonly IssueViewFactory $views,
        private readonly ProjectRepository $projectRepository,
        private readonly WorkStatuses $workStatuses,
    ) {
    }

    /**
     * @param Team[] $teams
     *
     * @return array<int, array{team: Team, members: DeveloperActivity[]}>
     */
    public function getTeamsActivity(array $teams): array
    {
        /** @var Developer[] $developers */
        $developers = [];
        foreach ($teams as $team) {
            foreach ($team->getActiveDevelopers() as $dev) {
                $developers[$dev->getJiraAccountId()] = $dev;
            }
        }

        $byAssignee = [];
        foreach ($this->searchCurrentWork(array_map('strval', array_keys($developers))) as $issue) {
            $byAssignee[$issue->assigneeId][] = $this->views->create($issue);
        }

        $result = [];
        $idle = [];
        foreach ($teams as $team) {
            $members = [];
            foreach ($team->getActiveDevelopers() as $dev) {
                $views = $byAssignee[$dev->getJiraAccountId()] ?? [];
                usort($views, fn (IssueView $a, IssueView $b) => $b->age->days <=> $a->age->days);
                $work = array_values(array_filter($views, fn (IssueView $v) => $this->workStatuses->isWork($v->issue, $dev->getRole())));
                $other = array_values(array_filter($views, fn (IssueView $v) => !$this->workStatuses->isWork($v->issue, $dev->getRole())));
                $activity = new DeveloperActivity($dev, $work, $other, $team->getWipLimit());
                if ($activity->isIdle()) {
                    $idle[$dev->getJiraAccountId()][] = $activity;
                }
                $members[] = $activity;
            }
            // Les membres en alerte en premier
            usort($members, fn (DeveloperActivity $a, DeveloperActivity $b) => [(int) !$a->isIdleAlert(), $a->developer->getDisplayName()] <=> [(int) !$b->isIdleAlert(), $b->developer->getDisplayName()]);
            $result[] = ['team' => $team, 'members' => $members];
        }

        $this->attachLastActivity($idle);

        return $result;
    }

    /**
     * Tickets en cours (catégorie "En cours" + statuts de travail paramétrés, tous rôles) des membres.
     * Les tickets en cours non assignés sont recherchés en parallèle (voir getUnassignedInProgress()).
     *
     * @param string[] $accountIds
     *
     * @return Issue[]
     */
    private function searchCurrentWork(array $accountIds): array
    {
        $inProgress = 'statusCategory = "In Progress"';
        $statuses = $this->workStatuses->getAllStatuses();
        $searches = [];

        if ([] !== $accountIds) {
            $assignees = sprintf('assignee in (%s)', JiraClient::quoteList($accountIds));
            // Dernier ticket touché, utile pour les membres sans ticket en cours
            $searches['recent'] = [$assignees.' AND updated >= -15d ORDER BY updated DESC', 300, false];
            $searches['work'] = [[] !== $statuses
                ? sprintf('%s AND (%s OR status in (%s)) ORDER BY updated DESC', $assignees, $inProgress, JiraClient::quoteList($statuses))
                : sprintf('%s AND %s ORDER BY updated DESC', $assignees, $inProgress), 300, true];
        }
        if ($scope = ProjectScope::jqlForAll($this->projectRepository->findBy(['active' => true]))) {
            $searches['unassigned'] = [$scope.' AND statusCategory = "In Progress" AND assignee is EMPTY ORDER BY updated DESC', 100, true];
        }

        $results = $this->jira->searchMany($searches);

        $unassigned = $results['unassigned'] ?? [];
        if ($unassigned instanceof AtlassianException) {
            throw $unassigned;
        }
        $this->unassigned = $this->views->createAll($unassigned);

        $recent = $results['recent'] ?? [];
        $this->recent = $recent instanceof AtlassianException ? null : $recent;

        $work = $results['work'] ?? [];
        if ($work instanceof AtlassianException) {
            if (400 !== $work->getCode() || [] === $statuses) {
                throw $work;
            }
            // Un statut paramétré n'existe plus dans Jira (renommé ?) : on se limite à la catégorie "En cours"
            $work = $this->jira->search(sprintf('assignee in (%s) AND %s ORDER BY updated DESC', JiraClient::quoteList($accountIds), $inProgress), 300, true);
        }

        return $work;
    }

    /**
     * Tickets en cours sans assigné dans les projets paramétrés.
     *
     * @return IssueView[]
     */
    public function getUnassignedInProgress(): array
    {
        if (null === $this->unassigned) {
            $this->searchCurrentWork([]);
        }

        return $this->unassigned;
    }

    /**
     * Pour les membres sans ticket en cours : dernier ticket sur lequel ils ont travaillé.
     *
     * @param array<string, DeveloperActivity[]> $idle
     */
    private function attachLastActivity(array $idle): void
    {
        if ([] === $idle) {
            return;
        }
        $issues = $this->recent ?? $this->jira->search(
            sprintf('assignee in (%s) AND updated >= -15d ORDER BY updated DESC', JiraClient::quoteList(array_map('strval', array_keys($idle)))),
            200,
        );

        /** @var array<string, Issue> $last */
        $last = [];
        foreach ($issues as $issue) {
            $last[$issue->assigneeId] ??= $issue;
        }
        foreach ($idle as $accountId => $activities) {
            if (isset($last[$accountId])) {
                $view = $this->views->create($last[$accountId]);
                foreach ($activities as $activity) {
                    $activity->lastActivity = $view;
                }
            }
        }
    }
}
