<?php

namespace App\Jira;

use App\Atlassian\AtlassianHttp;

/**
 * Client Jira (Cloud ou Server / Data Center) basé sur l'API REST v2 et l'API Agile.
 */
class JiraClient
{
    private const BASE_FIELDS = [
        'summary', 'status', 'issuetype', 'priority', 'assignee', 'components', 'labels',
        'fixVersions', 'created', 'updated', 'resolutiondate', 'project', 'parent', 'statuscategorychangedate',
    ];

    private ?bool $cloud = null;

    public function __construct(
        private readonly AtlassianHttp $jiraHttp,
        private readonly string $deployment,
        private readonly string $storyPointsField,
        private readonly string $sprintField,
        private readonly string $flaggedField,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->jiraHttp->isConfigured();
    }

    public function isCloud(): bool
    {
        if (null === $this->cloud) {
            $this->cloud = match (strtolower($this->deployment)) {
                'cloud' => true,
                'server', 'datacenter', 'dc' => false,
                default => str_contains($this->jiraHttp->getBaseUrl(), '.atlassian.net'),
            };
        }

        return $this->cloud;
    }

    public function browseUrl(string $key): string
    {
        return $this->jiraHttp->getBaseUrl().'/browse/'.rawurlencode($key);
    }

    public function boardUrl(int $boardId, ?string $projectKey = null): string
    {
        if ($this->isCloud() && $projectKey) {
            return sprintf('%s/jira/software/c/projects/%s/boards/%d', $this->jiraHttp->getBaseUrl(), rawurlencode($projectKey), $boardId);
        }

        return sprintf('%s/secure/RapidBoard.jspa?rapidView=%d', $this->jiraHttp->getBaseUrl(), $boardId);
    }

    public function searchUrl(string $jql): string
    {
        return $this->jiraHttp->getBaseUrl().'/issues/?jql='.rawurlencode($jql);
    }

    public function myself(): array
    {
        return $this->jiraHttp->get('rest/api/2/myself', [], 0);
    }

    /**
     * Recherche JQL paginée.
     *
     * @return Issue[]
     */
    public function search(string $jql, int $max = 500, bool $withChangelog = false, array $extraFields = []): array
    {
        $fields = array_values(array_unique(array_filter(array_merge(
            self::BASE_FIELDS,
            [$this->storyPointsField, $this->sprintField, $this->flaggedField],
            $extraFields,
        ))));

        $raw = [];
        $pageSize = min(100, $max);

        if ($this->isCloud()) {
            // Jira Cloud : nouvel endpoint /search/jql paginé par jeton
            $token = null;
            do {
                $query = ['jql' => $jql, 'maxResults' => $pageSize, 'fields' => implode(',', $fields)];
                if ($withChangelog) {
                    $query['expand'] = 'changelog';
                }
                if ($token) {
                    $query['nextPageToken'] = $token;
                }
                $data = $this->jiraHttp->get('rest/api/2/search/jql', $query);
                $raw = array_merge($raw, $data['issues'] ?? []);
                $token = $data['nextPageToken'] ?? null;
            } while ($token && \count($raw) < $max && !($data['isLast'] ?? false));
        } else {
            $startAt = 0;
            do {
                $query = ['jql' => $jql, 'startAt' => $startAt, 'maxResults' => $pageSize, 'fields' => implode(',', $fields)];
                if ($withChangelog) {
                    $query['expand'] = 'changelog';
                }
                $data = $this->jiraHttp->get('rest/api/2/search', $query);
                $issues = $data['issues'] ?? [];
                $raw = array_merge($raw, $issues);
                $startAt += \count($issues);
                $total = (int) ($data['total'] ?? 0);
            } while ([] !== $issues && $startAt < $total && \count($raw) < $max);
        }

        return array_map(fn (array $i) => $this->mapIssue($i), \array_slice($raw, 0, $max));
    }

    /**
     * Nombre de tickets correspondant à une JQL (limité à $max).
     */
    public function count(string $jql, int $max = 1000): int
    {
        return \count($this->search($jql, $max));
    }

    public function getBoard(int $boardId): array
    {
        return $this->jiraHttp->get(sprintf('rest/agile/1.0/board/%d', $boardId), [], 3600);
    }

    /**
     * @param string $state active, future, closed (ou plusieurs séparés par des virgules)
     *
     * @return Sprint[]
     */
    public function getSprints(int $boardId, string $state = 'active'): array
    {
        $sprints = [];
        $startAt = 0;
        do {
            $data = $this->jiraHttp->get(sprintf('rest/agile/1.0/board/%d/sprint', $boardId), [
                'state' => $state,
                'startAt' => $startAt,
                'maxResults' => 50,
            ]);
            $values = $data['values'] ?? [];
            foreach ($values as $value) {
                // Un board peut afficher des sprints d'autres boards : on garde ceux d'origine en priorité
                $sprints[] = Sprint::fromArray($value);
            }
            $startAt += \count($values);
        } while ([] !== $values && !($data['isLast'] ?? true) && $startAt < 2000);

        return $sprints;
    }

    /** @return array<int, array{id: string, name: string, released: bool, archived: bool, releaseDate: ?string}> */
    public function getVersions(string $projectKey): array
    {
        $data = $this->jiraHttp->get(sprintf('rest/api/2/project/%s/versions', rawurlencode($projectKey)), [], 600);

        return array_map(fn (array $v) => [
            'id' => (string) $v['id'],
            'name' => (string) $v['name'],
            'released' => (bool) ($v['released'] ?? false),
            'archived' => (bool) ($v['archived'] ?? false),
            'releaseDate' => $v['releaseDate'] ?? null,
        ], $data);
    }

    /** @return string[] */
    public function getComponents(string $projectKey): array
    {
        $data = $this->jiraHttp->get(sprintf('rest/api/2/project/%s/components', rawurlencode($projectKey)), [], 600);

        return array_map(fn (array $c) => (string) $c['name'], $data);
    }

    /** @return array<int, array{id: string, name: string, email: ?string, avatar: ?string}> */
    public function getAssignableUsers(string $projectKey): array
    {
        $data = $this->jiraHttp->get('rest/api/2/user/assignable/search', ['project' => $projectKey, 'maxResults' => 1000], 600);
        $users = [];
        foreach ($data as $u) {
            if (isset($u['accountType']) && 'atlassian' !== $u['accountType']) {
                continue; // ignore les comptes applicatifs (bots, intégrations)
            }
            $id = $u['accountId'] ?? $u['name'] ?? $u['key'] ?? null;
            if (!$id) {
                continue;
            }
            $users[] = [
                'id' => (string) $id,
                'name' => (string) ($u['displayName'] ?? $id),
                'email' => $u['emailAddress'] ?? null,
                'avatar' => $u['avatarUrls']['48x48'] ?? null,
            ];
        }
        usort($users, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $users;
    }

    public function mapIssue(array $raw): Issue
    {
        $f = $raw['fields'] ?? [];
        $assignee = $f['assignee'] ?? null;
        $status = $f['status'] ?? [];

        $sprints = [];
        foreach ((array) ($f[$this->sprintField] ?? []) as $sprint) {
            if (\is_array($sprint) && isset($sprint['name'])) {
                $sprints[] = (string) $sprint['name'];
            } elseif (\is_string($sprint) && preg_match('/name=([^,\]]+)/', $sprint, $m)) {
                $sprints[] = $m[1]; // format texte de Jira Server
            }
        }

        $points = $f[$this->storyPointsField] ?? null;

        return new Issue(
            key: (string) $raw['key'],
            summary: (string) ($f['summary'] ?? ''),
            url: $this->browseUrl((string) $raw['key']),
            projectKey: (string) ($f['project']['key'] ?? explode('-', (string) $raw['key'])[0]),
            status: (string) ($status['name'] ?? '?'),
            statusCategory: (string) ($status['statusCategory']['key'] ?? Issue::CATEGORY_TODO),
            type: (string) ($f['issuetype']['name'] ?? ''),
            typeIcon: $f['issuetype']['iconUrl'] ?? null,
            priority: $f['priority']['name'] ?? null,
            priorityIcon: $f['priority']['iconUrl'] ?? null,
            assigneeId: $assignee ? (string) ($assignee['accountId'] ?? $assignee['name'] ?? $assignee['key'] ?? '') : null,
            assigneeName: $assignee['displayName'] ?? null,
            assigneeAvatar: $assignee['avatarUrls']['48x48'] ?? null,
            components: array_map(fn ($c) => (string) $c['name'], (array) ($f['components'] ?? [])),
            labels: array_map('strval', (array) ($f['labels'] ?? [])),
            fixVersions: array_map(fn ($v) => (string) $v['name'], (array) ($f['fixVersions'] ?? [])),
            sprints: $sprints,
            storyPoints: is_numeric($points) ? (float) $points : null,
            flagged: !empty($f[$this->flaggedField]),
            created: self::date($f['created'] ?? null),
            updated: self::date($f['updated'] ?? null),
            resolved: self::date($f['resolutiondate'] ?? null),
            statusSince: $this->computeStatusSince($raw),
            parentKey: $f['parent']['key'] ?? null,
            parentSummary: $f['parent']['fields']['summary'] ?? null,
        );
    }

    /**
     * Date d'entrée dans le statut actuel : dernier changement de statut de l'historique,
     * à défaut la date de changement de catégorie, à défaut la date de création.
     */
    private function computeStatusSince(array $raw): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($raw['changelog']['histories'] ?? [] as $history) {
            foreach ($history['items'] ?? [] as $item) {
                if ('status' === ($item['field'] ?? null)) {
                    $date = self::date($history['created'] ?? null);
                    if ($date && (!$latest || $date > $latest)) {
                        $latest = $date;
                    }
                }
            }
        }

        $f = $raw['fields'] ?? [];

        return $latest ?? self::date($f['statuscategorychangedate'] ?? null) ?? self::date($f['created'] ?? null);
    }

    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }

    /** Échappe une valeur pour l'insérer entre guillemets dans une requête JQL */
    public static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** @param string[] $values */
    public static function quoteList(array $values): string
    {
        return implode(', ', array_map(self::quote(...), $values));
    }
}
