<?php

namespace App\Service;

use App\Entity\Project;
use App\Jira\JiraClient;

class BacklogService
{
    public function __construct(
        private readonly JiraClient $jira,
        private readonly IssueViewFactory $views,
    ) {
    }

    /**
     * Backlog d'un projet : tickets non terminés, hors sprint en cours (sauf si demandé).
     *
     * @param array{component?: ?string, label?: ?string, type?: ?string, text?: ?string, includeSprint?: bool} $filters
     *
     * @return IssueView[]
     */
    public function getBacklog(Project $project, array $filters = []): array
    {
        $jql = $this->buildJql($project, $filters);

        return $this->views->createAll($this->jira->search($jql, 1000), $project);
    }

    /**
     * Applique les filtres composant / étiquette / type à une backlog déjà chargée
     * (évite une seconde recherche Jira ; la recherche plein texte, elle, reste faite par Jira).
     *
     * @param IssueView[] $views
     *
     * @return IssueView[]
     */
    public function filter(array $views, array $filters): array
    {
        return array_values(array_filter($views, function (IssueView $view) use ($filters) {
            $issue = $view->issue;
            if (!empty($filters['component']) && !\in_array(mb_strtolower($filters['component']), array_map('mb_strtolower', $issue->components), true)) {
                return false;
            }
            if (!empty($filters['label'])) {
                if ('__none__' === $filters['label'] ? [] !== $issue->labels : !\in_array($filters['label'], $issue->labels, true)) {
                    return false;
                }
            }

            return empty($filters['type']) || 0 === strcasecmp($filters['type'], $issue->type);
        }));
    }

    public function buildJql(Project $project, array $filters = []): string
    {
        $parts = [ProjectScope::jql($project), 'statusCategory != Done', 'issuetype not in subTaskIssueTypes()'];
        if (empty($filters['includeSprint'])) {
            $parts[] = '(sprint is EMPTY OR sprint not in openSprints())';
        }
        if (!empty($filters['component'])) {
            $parts[] = 'component = '.JiraClient::quote($filters['component']);
        }
        if (!empty($filters['label'])) {
            $parts[] = '__none__' === $filters['label'] ? 'labels is EMPTY' : 'labels = '.JiraClient::quote($filters['label']);
        }
        if (!empty($filters['type'])) {
            $parts[] = 'issuetype = '.JiraClient::quote($filters['type']);
        }
        if (!empty($filters['text'])) {
            $parts[] = 'text ~ '.JiraClient::quote($filters['text']);
        }

        return implode(' AND ', $parts).' ORDER BY Rank ASC';
    }

    /**
     * Synthèse : nombre de tickets et points par composant / étiquette / type.
     *
     * @param IssueView[] $views
     *
     * @return array{components: array<string, int>, labels: array<string, int>, types: array<string, int>, points: float, unestimated: int, unlabelled: int}
     */
    public function summarize(array $views): array
    {
        $summary = ['components' => [], 'labels' => [], 'types' => [], 'points' => 0.0, 'unestimated' => 0, 'unlabelled' => 0];
        foreach ($views as $view) {
            $i = $view->issue;
            foreach ($i->components ?: ['(sans composant)'] as $c) {
                $summary['components'][$c] = ($summary['components'][$c] ?? 0) + 1;
            }
            foreach ($i->labels as $l) {
                $summary['labels'][$l] = ($summary['labels'][$l] ?? 0) + 1;
            }
            $summary['types'][$i->type] = ($summary['types'][$i->type] ?? 0) + 1;
            $summary['points'] += $i->storyPoints ?? 0;
            $summary['unestimated'] += null === $i->storyPoints ? 1 : 0;
            $summary['unlabelled'] += [] === $i->labels ? 1 : 0;
        }
        arsort($summary['components']);
        arsort($summary['labels']);
        arsort($summary['types']);

        return $summary;
    }
}
