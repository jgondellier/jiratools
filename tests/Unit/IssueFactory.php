<?php

namespace App\Tests\Unit;

use App\Jira\Issue;

final class IssueFactory
{
    public static function create(array $o = []): Issue
    {
        return new Issue(
            key: $o['key'] ?? 'APP-1',
            summary: $o['summary'] ?? 'Résumé',
            url: 'https://jira.test/browse/'.($o['key'] ?? 'APP-1'),
            projectKey: $o['projectKey'] ?? 'APP',
            status: $o['status'] ?? 'En cours',
            statusCategory: $o['statusCategory'] ?? Issue::CATEGORY_IN_PROGRESS,
            type: $o['type'] ?? 'Story',
            typeIcon: null,
            priority: null,
            priorityIcon: null,
            assigneeId: $o['assigneeId'] ?? null,
            assigneeName: null,
            assigneeAvatar: null,
            components: $o['components'] ?? [],
            labels: $o['labels'] ?? [],
            fixVersions: [],
            sprints: [],
            storyPoints: $o['storyPoints'] ?? null,
            flagged: false,
            created: null,
            updated: null,
            resolved: null,
            statusSince: $o['statusSince'] ?? null,
        );
    }
}
