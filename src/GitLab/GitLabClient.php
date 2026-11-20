<?php

namespace App\GitLab;

use App\Atlassian\AtlassianException;
use App\Atlassian\AtlassianHttp;

/**
 * Client GitLab (API REST v4, GitLab.com ou GitLab d'entreprise) : lecture des commits et merge requests.
 *
 * Authentification par Personal / Project Access Token (portée read_api), envoyé en Bearer.
 */
class GitLabClient
{
    private const PER_PAGE = 100;

    /** Garde-fou : au plus 30 pages (3 000 commits ou merge requests) par lecture */
    private const MAX_PAGES = 30;

    public function __construct(private readonly AtlassianHttp $gitlabHttp)
    {
    }

    public function isConfigured(): bool
    {
        return $this->gitlabHttp->isConfigured();
    }

    /** URL du serveur GitLab (sans /api/v4) */
    public function getBaseUrl(): string
    {
        return preg_replace('#/api/v4$#', '', $this->gitlabHttp->getBaseUrl()) ?? '';
    }

    /** @return array{name?: string, username?: string} */
    public function currentUser(): array
    {
        return $this->gitlabHttp->get('api/v4/user', [], 0);
    }

    /**
     * @param string $project chemin ("groupe/sous-groupe/projet") ou identifiant numérique
     *
     * @return array{id: int, name: string, path: string, webUrl: string, defaultBranch: ?string}
     */
    public function getProject(string $project): array
    {
        $data = $this->gitlabHttp->get(self::projectPath($project), [], 600);

        return [
            'id' => (int) ($data['id'] ?? 0),
            'name' => (string) ($data['name_with_namespace'] ?? $data['name'] ?? $project),
            'path' => (string) ($data['path_with_namespace'] ?? $project),
            'webUrl' => (string) ($data['web_url'] ?? ''),
            'defaultBranch' => $data['default_branch'] ?? null,
        ];
    }

    /**
     * Tags du dépôt, du plus récent au plus ancien (date du commit).
     *
     * @return array<int, array{name: string, sha: string, date: ?\DateTimeImmutable, message: string}>
     */
    public function getTags(int $projectId, int $limit = 20): array
    {
        $tags = $this->gitlabHttp->get(self::projectPath((string) $projectId).'/repository/tags', [
            'order_by' => 'updated',
            'sort' => 'desc',
            'per_page' => max(1, min(100, $limit)),
        ]);

        return array_map(fn (array $t) => [
            'name' => (string) $t['name'],
            'sha' => (string) ($t['commit']['id'] ?? $t['target'] ?? ''),
            'date' => self::date($t['commit']['committed_date'] ?? $t['commit']['created_at'] ?? null),
            'message' => (string) ($t['message'] ?? ''),
        ], \array_slice($tags, 0, $limit));
    }

    /** Tag le plus récent (date du commit), ou null si le projet n'a pas de tag */
    public function getLatestTag(int $projectId): ?string
    {
        $tags = $this->gitlabHttp->get(self::projectPath((string) $projectId).'/repository/tags', [
            'order_by' => 'updated',
            'sort' => 'desc',
            'per_page' => 1,
        ]);

        return isset($tags[0]['name']) ? (string) $tags[0]['name'] : null;
    }

    /**
     * Commits présents dans $branch et absents de $reference (équivalent de "git log reference..branch").
     *
     * @return array<int, array{sha: string, shortSha: string, title: string, message: string, author: string, date: ?\DateTimeImmutable, url: string, merge: bool}>
     */
    public function getCommitsBetween(int $projectId, string $reference, string $branch): array
    {
        // Référence vide : tout l'historique de la branche (limité à MAX_PAGES pages)
        $raw = $this->paginate(self::projectPath((string) $projectId).'/repository/commits', ['ref_name' => '' === $reference ? $branch : $reference.'..'.$branch]);

        return array_map(fn (array $c) => [
            'sha' => (string) $c['id'],
            'shortSha' => (string) ($c['short_id'] ?? substr((string) $c['id'], 0, 8)),
            'title' => (string) ($c['title'] ?? ''),
            'message' => (string) ($c['message'] ?? $c['title'] ?? ''),
            'author' => (string) ($c['author_name'] ?? ''),
            'date' => self::date($c['committed_date'] ?? $c['created_at'] ?? null),
            'url' => (string) ($c['web_url'] ?? ''),
            'merge' => \count((array) ($c['parent_ids'] ?? [])) > 1,
        ], $raw);
    }

    /**
     * Merge requests fusionnées (dans $targetBranch, ou dans n'importe quelle branche si null), mises à jour depuis $since.
     *
     * @return array<int, array{iid: int, title: string, description: string, sourceBranch: string, author: string, mergedAt: ?\DateTimeImmutable, url: string, shas: string[]}>
     */
    public function getMergedMergeRequests(int $projectId, ?string $targetBranch, \DateTimeImmutable $since): array
    {
        $raw = $this->paginate(self::projectPath((string) $projectId).'/merge_requests', array_filter([
            'state' => 'merged',
            'target_branch' => $targetBranch,
            'updated_after' => $since->format(DATE_ATOM),
            'order_by' => 'updated_at',
            'sort' => 'desc',
        ]));

        return array_map(fn (array $mr) => [
            'iid' => (int) $mr['iid'],
            'title' => (string) ($mr['title'] ?? ''),
            'description' => (string) ($mr['description'] ?? ''),
            'sourceBranch' => (string) ($mr['source_branch'] ?? ''),
            'author' => (string) ($mr['author']['name'] ?? ''),
            'mergedAt' => self::date($mr['merged_at'] ?? null),
            'url' => (string) ($mr['web_url'] ?? ''),
            // Commit de fusion, commit "squash" ou dernier commit (fusion en fast-forward)
            'shas' => array_values(array_filter([$mr['merge_commit_sha'] ?? null, $mr['squash_commit_sha'] ?? null, $mr['sha'] ?? null])),
            'targetBranch' => (string) ($mr['target_branch'] ?? ''),
        ], $raw);
    }

    /**
     * Commits de plusieurs merge requests, lus en parallèle.
     *
     * @param int[] $iids
     *
     * @return array<int, array<int, array{sha: string, shortSha: string, title: string, message: string, author: string, date: ?\DateTimeImmutable, url: string}>> iid => commits
     */
    public function getMergeRequestCommits(int $projectId, array $iids): array
    {
        $requests = [];
        foreach (array_unique($iids) as $iid) {
            $requests[$iid] = [sprintf('%s/merge_requests/%d/commits', self::projectPath((string) $projectId), $iid), ['per_page' => self::PER_PAGE]];
        }
        $result = [];
        foreach ($this->gitlabHttp->getMany($requests) as $iid => $data) {
            if ($data instanceof AtlassianException) {
                throw $data;
            }
            $result[$iid] = array_map(fn (array $c) => [
                'sha' => (string) $c['id'],
                'shortSha' => (string) ($c['short_id'] ?? substr((string) $c['id'], 0, 8)),
                'title' => (string) ($c['title'] ?? strtok((string) ($c['message'] ?? ''), "\n")),
                'message' => (string) ($c['message'] ?? $c['title'] ?? ''),
                'author' => (string) ($c['author_name'] ?? ''),
                'date' => self::date($c['committed_date'] ?? $c['created_at'] ?? null),
                'url' => (string) ($c['web_url'] ?? ''),
            ], $data);
        }

        return $result;
    }

    private function paginate(string $path, array $query): array
    {
        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $data = $this->gitlabHttp->get($path, $query + ['per_page' => self::PER_PAGE, 'page' => $page]);
            $items = array_merge($items, $data);
            if (\count($data) < self::PER_PAGE) {
                break;
            }
        }

        return $items;
    }

    /** Lien GitLab d'un tag */
    public static function tagUrl(string $projectWebUrl, string $tag): string
    {
        return rtrim($projectWebUrl, '/').'/-/tags/'.rawurlencode($tag);
    }

    /** "groupe/projet" => api/v4/projects/groupe%2Fprojet */
    private static function projectPath(string $project): string
    {
        return 'api/v4/projects/'.rawurlencode(trim($project, '/'));
    }

    private static function date(mixed $value): ?\DateTimeImmutable
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
}
