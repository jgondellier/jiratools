<?php

namespace App\Confluence;

use App\Atlassian\AtlassianException;
use App\Atlassian\AtlassianHttp;
use App\Jira\JiraClient;

/**
 * Client Confluence (API REST "content", disponible sur Cloud et Server / Data Center).
 * Pour Confluence Cloud l'URL de base se termine par /wiki.
 */
class ConfluenceClient
{
    public function __construct(private readonly AtlassianHttp $confluenceHttp)
    {
    }

    public function isConfigured(): bool
    {
        return $this->confluenceHttp->isConfigured();
    }

    public function currentUser(): array
    {
        return $this->confluenceHttp->get('rest/api/user/current', [], 0);
    }

    public function getPage(string $pageId): ConfluencePageInfo
    {
        $data = $this->confluenceHttp->get(
            'rest/api/content/'.rawurlencode($pageId),
            ['expand' => 'version,space,history.lastUpdated'],
            900,
        );

        return $this->mapPage($data);
    }

    /**
     * Recherche une page par titre dans un espace.
     */
    public function findPage(string $spaceKey, string $title): ?ConfluencePageInfo
    {
        $data = $this->confluenceHttp->get('rest/api/content', [
            'spaceKey' => $spaceKey,
            'title' => $title,
            'type' => 'page',
            'expand' => 'version,space',
        ], 0);
        $result = $data['results'][0] ?? null;

        return $result ? $this->mapPage($result) : null;
    }

    /**
     * Crée la page, ou la met à jour si une page du même titre existe déjà dans l'espace.
     */
    public function publishPage(string $spaceKey, ?string $parentId, string $title, string $storageBody): ConfluencePageInfo
    {
        $existing = $this->findPage($spaceKey, $title);

        $payload = [
            'type' => 'page',
            'title' => $title,
            'space' => ['key' => $spaceKey],
            'body' => ['storage' => ['value' => $storageBody, 'representation' => 'storage']],
        ];
        if ($parentId) {
            $payload['ancestors'] = [['id' => $parentId]];
        }

        if ($existing) {
            $payload['id'] = $existing->id;
            $payload['version'] = ['number' => $existing->version + 1, 'message' => 'Mise à jour automatique (Jira Tools)'];
            $data = $this->confluenceHttp->request('PUT', 'rest/api/content/'.rawurlencode($existing->id), ['json' => $payload]);
        } else {
            $data = $this->confluenceHttp->request('POST', 'rest/api/content', ['json' => $payload]);
        }

        if (!isset($data['id'])) {
            throw new AtlassianException('Réponse inattendue de Confluence lors de la publication.');
        }

        return $this->mapPage($data);
    }

    private function mapPage(array $data): ConfluencePageInfo
    {
        $base = $data['_links']['base'] ?? $this->confluenceHttp->getBaseUrl();
        $webui = $data['_links']['webui'] ?? '/pages/viewpage.action?pageId='.$data['id'];
        $lastUpdated = $data['history']['lastUpdated']['when'] ?? $data['version']['when'] ?? null;
        $by = $data['history']['lastUpdated']['by']['displayName'] ?? $data['version']['by']['displayName'] ?? null;

        return new ConfluencePageInfo(
            id: (string) $data['id'],
            title: (string) ($data['title'] ?? ''),
            url: rtrim($base, '/').$webui,
            spaceKey: $data['space']['key'] ?? null,
            version: (int) ($data['version']['number'] ?? 1),
            lastUpdated: JiraClient::date($lastUpdated),
            lastUpdatedBy: $by,
        );
    }
}
