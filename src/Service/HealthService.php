<?php

namespace App\Service;

use App\Atlassian\AtlassianException;
use App\Confluence\ConfluenceClient;
use App\Confluence\ConfluencePageInfo;
use App\Entity\ConfluencePage;
use App\Entity\Project;
use App\Jira\JiraClient;

/**
 * Indicateurs de suivi et d'hygiène : tickets qui traînent, bloqués, non estimés, etc.
 */
class HealthService
{
    public function __construct(
        private readonly JiraClient $jira,
        private readonly ConfluenceClient $confluence,
        private readonly IssueViewFactory $views,
        private readonly StatusAgeEvaluator $evaluator,
        private readonly string $doneStatuses = 'Done',
    ) {
    }

    /**
     * Tickets dépassant le seuil de leur statut, du plus critique au moins critique.
     *
     * @param Project[] $projects
     *
     * @return IssueView[]
     */
    public function getStuckIssues(array $projects): array
    {
        $scope = ProjectScope::jqlForAll($projects);
        if (!$scope) {
            return [];
        }
        $statusFilter = 'statusCategory = "In Progress"';
        $ruled = $this->evaluator->getRuledStatuses();
        if ([] !== $ruled) {
            $statusFilter = sprintf('(%s OR status in (%s))', $statusFilter, JiraClient::quoteList($ruled));
        }

        try {
            $issues = $this->jira->search("$scope AND statusCategory != Done AND $statusFilter ORDER BY updated ASC", 500, true);
        } catch (AtlassianException $e) {
            if (400 !== $e->getCode()) {
                throw $e;
            }
            // Un statut paramétré n'existe pas dans Jira : on se limite aux statuts "En cours"
            $issues = $this->jira->search("$scope AND statusCategory = \"In Progress\" ORDER BY updated ASC", 500, true);
        }

        $views = array_filter($this->views->createAll($issues), fn (IssueView $v) => $v->age->isAlert());
        usort($views, fn (IssueView $a, IssueView $b) => [StatusAge::severity($b->age->level), $b->age->days] <=> [StatusAge::severity($a->age->level), $a->age->days]);

        return $views;
    }

    /**
     * Contrôles d'hygiène des tickets.
     *
     * @param Project[] $projects
     *
     * @return array<string, array{title: string, help: string, jql: string, url: string, issues: IssueView[], error: ?string}>
     */
    public function getHygieneChecks(array $projects): array
    {
        $scope = ProjectScope::jqlForAll($projects);
        if (!$scope) {
            return [];
        }
        $keys = JiraClient::quoteList(ProjectScope::jiraKeys($projects));
        $done = JiraClient::quoteList(array_filter(array_map('trim', explode(',', $this->doneStatuses))) ?: ['Done']);

        $checks = [
            'flagged' => [
                'Tickets signalés / bloqués',
                'Tickets marqués avec le drapeau "Flagged" (obstacle) et non terminés.',
                "$scope AND flagged is not EMPTY AND statusCategory != Done ORDER BY priority DESC",
            ],
            'sprint_no_estimate' => [
                'Sprint en cours : tickets non estimés',
                'Tickets du sprint actif sans estimation (story points).',
                "$scope AND sprint in openSprints() AND statusCategory != Done AND issuetype not in subTaskIssueTypes() ORDER BY Rank ASC",
            ],
            'sprint_unassigned' => [
                'Sprint en cours : tickets non assignés',
                'Tickets du sprint actif sans personne assignée.',
                "$scope AND sprint in openSprints() AND assignee is EMPTY AND statusCategory != Done ORDER BY Rank ASC",
            ],
            'no_component' => [
                'Tickets sans composant',
                'Tickets non terminés des projets Jira suivis sans composant : ils n\'apparaissent dans aucune backlog projet.',
                "project in ($keys) AND component is EMPTY AND statusCategory != Done AND issuetype not in subTaskIssueTypes() ORDER BY created DESC",
            ],
            'old_bugs' => [
                'Anomalies ouvertes depuis plus de 60 jours',
                'Bugs non résolus créés il y a plus de deux mois.',
                "$scope AND issuetype = Bug AND statusCategory != Done AND created <= -60d ORDER BY created ASC",
            ],
            'reopened' => [
                'Tickets réouverts récemment',
                'Tickets revenus d\'un statut "Terminé" vers un autre statut ces 30 derniers jours (signe de qualité à surveiller).',
                "$scope AND status changed FROM ($done) DURING (-30d, now()) AND statusCategory != Done",
            ],
        ];

        $result = [];
        foreach ($checks as $id => [$title, $help, $jql]) {
            $error = null;
            try {
                $issues = $this->jira->search($jql, 200);
            } catch (AtlassianException $e) {
                if (400 !== $e->getCode()) {
                    throw $e;
                }
                $issues = [];
                $error = $e->getMessage();
            }
            if ('sprint_no_estimate' === $id) {
                $issues = array_values(array_filter($issues, fn ($i) => null === $i->storyPoints));
            }
            $result[$id] = [
                'title' => $title,
                'help' => $help,
                'jql' => $jql,
                'url' => $this->jira->searchUrl($jql),
                'issues' => $this->views->createAll($issues),
                'error' => $error,
            ];
        }

        return $result;
    }

    /**
     * Informations Confluence (date de mise à jour, obsolescence) des pages d'un ou plusieurs projets.
     *
     * @param ConfluencePage[] $pages
     *
     * @return array<int, array{page: ConfluencePage, info: ?ConfluencePageInfo, error: ?string, stale: bool}>
     */
    public function getPagesStatus(array $pages): array
    {
        usort($pages, fn (ConfluencePage $a, ConfluencePage $b) => [$a->getCategory(), $a->getTitle()] <=> [$b->getCategory(), $b->getTitle()]);
        $result = [];
        foreach ($pages as $page) {
            $info = null;
            $error = null;
            if ($page->getPageId() && $this->confluence->isConfigured()) {
                try {
                    $info = $this->confluence->getPage($page->getPageId());
                } catch (AtlassianException $e) {
                    $error = $e->getMessage();
                }
            }
            $stale = $info && $page->getMaxAgeDays() && $info->getAgeDays() > $page->getMaxAgeDays();
            $result[] = ['page' => $page, 'info' => $info, 'error' => $error, 'stale' => (bool) $stale];
        }

        return $result;
    }
}
