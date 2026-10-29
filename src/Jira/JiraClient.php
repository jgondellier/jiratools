<?php

namespace App\Jira;

use App\Atlassian\AtlassianException;
use App\Atlassian\AtlassianHttp;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Client Jira (Cloud ou Server / Data Center) basé sur l'API REST v2 et l'API Agile.
 */
class JiraClient
{
    private const BASE_FIELDS = [
        'summary', 'status', 'issuetype', 'priority', 'assignee', 'components', 'labels',
        'fixVersions', 'created', 'updated', 'resolutiondate', 'project', 'parent', 'statuscategorychangedate',
    ];

    /** Tri de remplacement quand Jira refuse le tri par rang */
    public const FALLBACK_ORDER = 'priority DESC, created ASC';

    /** Mémorise (1 h) que le tri par rang est refusé, pour ne pas renvoyer une requête en erreur à chaque fois */
    private const RANK_UNAVAILABLE_KEY = 'jira_rank_unavailable';

    /** Repère "ORDER BY Rank" dans les JQL de l'application */
    private const RANK_ORDER_PATTERN = '/\bORDER\s+BY\s+Rank\s+(ASC|DESC)\b/i';

    private ?bool $cloud = null;
    private ?bool $rankUnavailable = null;

    public function __construct(
        private readonly AtlassianHttp $jiraHttp,
        private readonly string $deployment,
        private readonly string $storyPointsField,
        private readonly string $sprintField,
        private readonly string $flaggedField,
        private readonly string $rankField = 'Rank',
        private readonly ?CacheInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Le tri par rang (ordre du board) a-t-il été refusé par Jira ? */
    public function isRankUnavailable(): bool
    {
        if (null === $this->rankUnavailable) {
            $this->rankUnavailable = (bool) $this->cache?->get(self::RANK_UNAVAILABLE_KEY, fn () => false);
        }

        return $this->rankUnavailable;
    }

    private function markRankUnavailable(): void
    {
        $this->rankUnavailable = true;
        if ($this->cache) {
            $this->cache->delete(self::RANK_UNAVAILABLE_KEY);
            $this->cache->get(self::RANK_UNAVAILABLE_KEY, function ($item) {
                $item->expiresAfter(3600);

                return true;
            });
        }
    }

    /**
     * Remplace "ORDER BY Rank" par le champ de rang paramétré, ou par le tri de remplacement.
     */
    private function applyRankOrder(string $jql, bool $fallback): string
    {
        return preg_replace_callback(self::RANK_ORDER_PATTERN, fn (array $m) => $fallback
            ? 'ORDER BY '.self::FALLBACK_ORDER
            : sprintf('ORDER BY %s %s', $this->rankField ?: 'Rank', strtoupper($m[1])), $jql) ?? $jql;
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
        return $this->jiraHttp->getBaseUrl().'/issues/?jql='.rawurlencode($this->effectiveJql($jql));
    }

    public function myself(): array
    {
        return $this->jiraHttp->get('rest/api/2/myself', [], 0);
    }

    /**
     * Recherche JQL paginée.
     *
     * @return Issue[]
     *
     * @throws AtlassianException
     */
    public function search(string $jql, int $max = 500, bool $withChangelog = false): array
    {
        $result = $this->searchMany(['_' => [$jql, $max, $withChangelog]])['_'];
        if ($result instanceof AtlassianException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Plusieurs recherches JQL envoyées en parallèle (y compris les pages suivantes).
     *
     * Les JQL triées par "ORDER BY Rank" utilisent le champ JIRA_RANK_FIELD ; si Jira refuse ce tri
     * (champ Rank absent, ambigu ou non triable), la recherche est relancée avec un tri de remplacement.
     *
     * @param array<string, array{0: string, 1?: int, 2?: bool}> $searches clé => [jql, nombre max, avec historique]
     *
     * @return array<string, Issue[]|AtlassianException> clé => tickets, ou l'erreur de cette recherche
     */
    public function searchMany(array $searches): array
    {
        $fields = implode(',', array_values(array_unique(array_filter(array_merge(
            self::BASE_FIELDS,
            [$this->storyPointsField, $this->sprintField, $this->flaggedField],
        )))));

        $states = [];
        foreach ($searches as $key => $search) {
            [$jql, $max, $changelog] = $search + [1 => 500, 2 => false];
            $states[$key] = [
                'jql' => $this->effectiveJql($jql),
                // Le tri de remplacement n'est tenté que si le tri par rang a été demandé et pas encore refusé
                'canFallback' => preg_match(self::RANK_ORDER_PATTERN, $jql) && !$this->isRankUnavailable(),
                'original' => $jql,
                'max' => $max,
                'changelog' => $changelog,
                // L'historique est volumineux : pages de 100 ; sinon pages plus grandes sur Jira Server
                'pageSize' => min($max, $changelog || $this->isCloud() ? 100 : 500),
                'raw' => [],
                'token' => null,
                'startAt' => 0,
                'done' => false,
            ];
        }

        $results = [];
        while ($active = array_filter($states, fn (array $st) => !$st['done'])) {
            $requests = [];
            foreach ($active as $key => $st) {
                $query = ['jql' => $st['jql'], 'maxResults' => $st['pageSize'], 'fields' => $fields];
                if ($st['changelog']) {
                    $query['expand'] = 'changelog';
                }
                if ($this->isCloud()) {
                    // Jira Cloud : endpoint /search/jql paginé par jeton
                    if ($st['token']) {
                        $query['nextPageToken'] = $st['token'];
                    }
                    $requests[$key] = ['rest/api/2/search/jql', $query];
                } else {
                    $query['startAt'] = $st['startAt'];
                    $requests[$key] = ['rest/api/2/search', $query];
                }
            }

            foreach ($this->jiraHttp->getMany($requests) as $key => $data) {
                $st = &$states[$key];
                if ($data instanceof AtlassianException) {
                    if (400 === $data->getCode() && $st['canFallback'] && [] === $st['raw']) {
                        $this->logger?->warning('Tri par rang refusé par Jira ({msg}), tri de remplacement : {fallback}', ['msg' => $data->getMessage(), 'fallback' => self::FALLBACK_ORDER]);
                        $st['jql'] = $this->applyRankOrder($st['original'], true);
                        $st['canFallback'] = false;
                        $st['fallbackInUse'] = true;
                        continue;
                    }
                    $results[$key] = $data;
                    $st['done'] = true;
                    continue;
                }

                if (!empty($st['fallbackInUse'])) {
                    // Le tri de remplacement fonctionne : c'est donc bien le tri par rang qui était refusé
                    $this->markRankUnavailable();
                    $st['fallbackInUse'] = false;
                }

                $issues = $data['issues'] ?? [];
                $st['raw'] = array_merge($st['raw'], $issues);
                if ($this->isCloud()) {
                    $st['token'] = $data['nextPageToken'] ?? null;
                    $more = $st['token'] && !($data['isLast'] ?? false);
                } else {
                    $st['startAt'] += \count($issues);
                    $more = [] !== $issues && $st['startAt'] < (int) ($data['total'] ?? 0);
                }
                $st['done'] = !$more || \count($st['raw']) >= $st['max'];
            }
            unset($st);
        }

        foreach ($states as $key => $st) {
            $results[$key] ??= array_map(fn (array $i) => $this->mapIssue($i), \array_slice($st['raw'], 0, $st['max']));
        }

        return array_replace($searches, $results);
    }

    /**
     * JQL telle qu'envoyée à Jira (tri par rang adapté), pour l'affichage et les liens.
     */
    public function effectiveJql(string $jql): string
    {
        return preg_match(self::RANK_ORDER_PATTERN, $jql) ? $this->applyRankOrder($jql, $this->isRankUnavailable()) : $jql;
    }

    /**
     * @param string $state active, future ou closed
     *
     * @return Sprint[]
     */
    public function getSprints(int $boardId, string $state = 'active'): array
    {
        return $this->getSprintsMany([$boardId], [$state])[$state][$boardId];
    }

    /**
     * Sprints de plusieurs boards et états, lus en parallèle. Les sprints clôturés et futurs changent
     * rarement : ils sont gardés en cache plus longtemps que les données des tickets.
     *
     * @param int[]    $boardIds
     * @param string[] $states   active, future, closed
     *
     * @return array<string, array<int, Sprint[]>> état => identifiant du board => sprints
     */
    public function getSprintsMany(array $boardIds, array $states = ['active']): array
    {
        $boardIds = array_values(array_unique(array_map('intval', $boardIds)));
        $result = [];
        $pending = [];
        foreach ($states as $state) {
            $result[$state] = array_fill_keys($boardIds, []);
            foreach ($boardIds as $boardId) {
                $pending[$state.'|'.$boardId] = 0; // startAt
            }
        }

        while ([] !== $pending) {
            $requests = [];
            foreach ($pending as $key => $startAt) {
                [$state, $boardId] = explode('|', $key);
                $requests[$key] = [sprintf('rest/agile/1.0/board/%d/sprint', $boardId), [
                    'state' => $state,
                    'startAt' => $startAt,
                    'maxResults' => 50,
                ], match ($state) {
                    'closed' => 3600,
                    'future' => 600,
                    default => null,
                }];
            }
            $next = [];
            foreach ($this->jiraHttp->getMany($requests) as $key => $data) {
                if ($data instanceof AtlassianException) {
                    throw $data;
                }
                [$state, $boardId] = explode('|', $key);
                $values = $data['values'] ?? [];
                foreach ($values as $value) {
                    $result[$state][(int) $boardId][] = Sprint::fromArray($value);
                }
                $startAt = $pending[$key] + \count($values);
                if ([] !== $values && !($data['isLast'] ?? true) && $startAt < 2000) {
                    $next[$key] = $startAt;
                }
            }
            $pending = $next;
        }

        return $result;
    }

    /**
     * Statuts utilisés par les projets Jira donnés (tous les statuts de l'instance si aucun projet).
     *
     * @param string[] $projectKeys
     *
     * @return array<string, array{name: string, category: string, categoryName: string}> indexé par nom en minuscules
     */
    public function getStatuses(array $projectKeys = []): array
    {
        $raw = [];
        if ([] === $projectKeys) {
            $raw = $this->jiraHttp->get('rest/api/2/status', [], 3600);
        }
        foreach ($projectKeys as $key) {
            foreach ($this->jiraHttp->get(sprintf('rest/api/2/project/%s/statuses', rawurlencode($key)), [], 3600) as $issueType) {
                $raw = array_merge($raw, $issueType['statuses'] ?? []);
            }
        }

        $statuses = [];
        foreach ($raw as $status) {
            $name = (string) ($status['name'] ?? '');
            if ('' === $name) {
                continue;
            }
            $statuses[mb_strtolower($name)] ??= [
                'name' => $name,
                'category' => (string) ($status['statusCategory']['key'] ?? Issue::CATEGORY_TODO),
                'categoryName' => (string) ($status['statusCategory']['name'] ?? ''),
            ];
        }
        uasort($statuses, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $statuses;
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
        $sprintIds = [];
        foreach ((array) ($f[$this->sprintField] ?? []) as $sprint) {
            if (\is_array($sprint) && isset($sprint['name'])) {
                $sprints[] = (string) $sprint['name'];
                $sprintIds[] = (int) ($sprint['id'] ?? 0);
            } elseif (\is_string($sprint) && preg_match('/name=([^,\]]+)/', $sprint, $m)) {
                // Format texte de Jira Server : com.atlassian.greenhopper...Sprint@1a2b[id=12,...,name=Sprint 12,...]
                $sprints[] = $m[1];
                $sprintIds[] = preg_match('/\bid=(\d+)/', $sprint, $id) ? (int) $id[1] : 0;
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
            sprintIds: array_values(array_filter($sprintIds)),
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
