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
            fixVersions: array_map(fn (array $v) => $v['name'], $o['versions'] ?? []),
            sprints: [],
            storyPoints: $o['storyPoints'] ?? null,
            flagged: false,
            created: null,
            updated: null,
            resolved: null,
            statusSince: $o['statusSince'] ?? null,
            parentKey: $o['parentKey'] ?? null,
            // [['id' => '10', 'name' => '1.5.0', 'released' => false]]
            versionDetails: array_map(fn (array $v) => $v + ['id' => '', 'releaseDate' => null, 'released' => false], $o['versions'] ?? []),
        );
    }
}
