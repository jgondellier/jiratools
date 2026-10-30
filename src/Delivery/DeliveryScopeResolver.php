<?php

namespace App\Delivery;

use App\Atlassian\AtlassianException;
use App\Entity\Project;
use App\Jira\JiraClient;
use App\Service\ProjectScope;

class DeliveryScopeResolver
{
    public function __construct(
        private readonly JiraClient $jira,
        private readonly DeliverySettings $settings,
    ) {
    }

    /**
     * @param Project[] $projects mes projets
     */
    public function resolve(array $projects): DeliveryScope
    {
        $myKeys = ProjectScope::jiraKeys($projects);
        $wsKeys = $this->settings->getWsKeys();
        $labels = $this->settings->getWsLabels();
        $boards = [];
        $errors = [];

        foreach (array_unique(array_filter(array_map(fn (Project $p) => $p->getJiraBoardId(), $projects))) as $boardId) {
            try {
                $board = $this->jira->getBoardScope($boardId);
            } catch (AtlassianException $e) {
                $errors[] = sprintf('Board %d : %s', $boardId, $e->getMessage());
                continue;
            }
            $boards[] = $board;
            // Projets du board autres que les miens = projets web services ; étiquettes citées dans le filtre
            $wsKeys = array_merge($wsKeys, array_diff($board['projects'], $myKeys));
            $labels = array_merge($labels, JiraClient::labelsInJql($board['jql']));
        }

        return new DeliveryScope(
            myKeys: $myKeys,
            wsKeys: array_values(array_diff(array_unique($wsKeys), $myKeys)),
            labels: array_values(array_unique($labels)),
            myJql: ProjectScope::jqlForAll($projects) ?? 'project is EMPTY',
            boards: $boards,
            errors: $errors,
        );
    }
}
