<?php

namespace App\Service;

use App\Entity\Project;
use App\Jira\Issue;
use App\Jira\JiraClient;

/**
 * Traduit le paramétrage d'un projet (clé Jira + composants + JQL additionnelle) en JQL.
 */
class ProjectScope
{
    public static function jql(Project $project): string
    {
        $parts = ['project = '.JiraClient::quote($project->getJiraProjectKey())];
        if ([] !== $project->getComponents()) {
            $parts[] = 'component in ('.JiraClient::quoteList($project->getComponents()).')';
        }
        if ($project->getExtraJql()) {
            $parts[] = '('.$project->getExtraJql().')';
        }

        return implode(' AND ', $parts);
    }

    /** @param Project[] $projects */
    public static function jqlForAll(array $projects): ?string
    {
        if ([] === $projects) {
            return null;
        }

        return '(('.implode(') OR (', array_map(self::jql(...), $projects)).'))';
    }

    /** @param Project[] $projects */
    public static function jiraKeys(array $projects): array
    {
        return array_values(array_unique(array_map(fn (Project $p) => $p->getJiraProjectKey(), $projects)));
    }

    /**
     * Retrouve le projet applicatif d'un ticket (clé Jira + composant).
     *
     * @param Project[] $projects
     */
    public static function findProject(Issue $issue, array $projects): ?Project
    {
        $fallback = null;
        foreach ($projects as $project) {
            if ($project->getJiraProjectKey() !== $issue->projectKey) {
                continue;
            }
            if ([] === $project->getComponents()) {
                $fallback ??= $project;
                continue;
            }
            if (array_intersect(array_map('mb_strtolower', $project->getComponents()), array_map('mb_strtolower', $issue->components))) {
                return $project;
            }
        }

        return $fallback;
    }
}
