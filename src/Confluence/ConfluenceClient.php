<?php

namespace App\Confluence;

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
