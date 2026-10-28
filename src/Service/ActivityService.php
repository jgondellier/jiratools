<?php

namespace App\Service;

use App\Entity\Developer;
use App\Entity\Team;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;

/**
 * "Qui fait quoi" : ticket(s) en cours de chaque membre, membres sans ticket.
 */
class ActivityService
{
    public function __construct(
        private readonly JiraClient $jira,
        private readonly IssueViewFactory $views,
        private readonly ProjectRepository $projectRepository,
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
        if ([] !== $developers) {
            $ids = JiraClient::quoteList(array_map('strval', array_keys($developers)));
            $issues = $this->jira->search(
                sprintf('assignee in (%s) AND statusCategory = "In Progress" ORDER BY updated DESC', $ids),
                300,
                true,
            );
            foreach ($issues as $issue) {
                $byAssignee[$issue->assigneeId][] = $this->views->create($issue);
            }
        }

        $result = [];
        $idle = [];
        foreach ($teams as $team) {
            $members = [];
            foreach ($team->getActiveDevelopers() as $dev) {
                $views = $byAssignee[$dev->getJiraAccountId()] ?? [];
                usort($views, fn (IssueView $a, IssueView $b) => $b->age->days <=> $a->age->days);
                $activity = new DeveloperActivity($dev, $views, $team->getWipLimit());
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
     * Tickets en cours sans assigné dans les projets paramétrés.
     *
     * @return IssueView[]
     */
    public function getUnassignedInProgress(): array
    {
        $scope = ProjectScope::jqlForAll($this->projectRepository->findBy(['active' => true]));
        if (!$scope) {
            return [];
        }

        return $this->views->createAll($this->jira->search(
            $scope.' AND statusCategory = "In Progress" AND assignee is EMPTY ORDER BY updated DESC',
            100,
            true,
        ));
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
        $ids = JiraClient::quoteList(array_map('strval', array_keys($idle)));
        $issues = $this->jira->search(sprintf('assignee in (%s) AND updated >= -15d ORDER BY updated DESC', $ids), 200);

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
