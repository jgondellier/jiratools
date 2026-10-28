<?php

namespace App\Atlassian;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Socle HTTP commun Jira / Confluence : authentification, cache et gestion d'erreurs.
 *
 * - Jira / Confluence Cloud : identifiant = e-mail, jeton = API token (Basic auth)
 * - Server / Data Center    : identifiant vide, jeton = Personal Access Token (Bearer)
 */
class AtlassianHttp
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ?RequestStack $requestStack,
        private readonly ?LoggerInterface $logger,
        private readonly string $label,
        private readonly string $baseUrl,
        private readonly string $user,
        private readonly string $token,
        private readonly int $cacheTtl = 60,
        private readonly bool $verifySsl = true,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->baseUrl) && '' !== trim($this->token);
    }

    public function getBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Requête GET mise en cache (durée : APP_CACHE_TTL secondes).
     * Ajouter ?refresh=1 à l'URL d'une page de l'application pour forcer le rafraîchissement.
     */
    public function get(string $path, array $query = [], ?int $ttl = null): array
    {
        $ttl ??= $this->cacheTtl;
        $key = 'atl_'.md5($this->label.$this->baseUrl.$path.serialize($query));

        if ($ttl <= 0 || $this->isRefreshRequested()) {
            $this->cache->delete($key);
        }
        if ($ttl <= 0) {
            return $this->request('GET', $path, ['query' => $query]);
        }

        return $this->cache->get($key, function (ItemInterface $item) use ($path, $query, $ttl) {
            $item->expiresAfter($ttl);

            return $this->request('GET', $path, ['query' => $query]);
        });
    }

    public function request(string $method, string $path, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new AtlassianException(sprintf('%s n\'est pas configuré : renseignez l\'URL et le jeton dans le fichier .env.local.', $this->label));
        }

        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
        if ('' !== trim($this->user)) {
            $options['auth_basic'] = [$this->user, $this->token];
        } else {
            $options['auth_bearer'] = $this->token;
        }
        $options['timeout'] ??= 30;
        if (!$this->verifySsl) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }

        $url = $this->getBaseUrl().'/'.ltrim($path, '/');

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger?->error('{label} injoignable : {msg}', ['label' => $this->label, 'msg' => $e->getMessage()]);
            throw new AtlassianException(sprintf('%s injoignable (%s) : %s', $this->label, $url, $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            $message = $this->extractError($content) ?: 'HTTP '.$status;
            $this->logger?->warning('{label} {method} {url} : {status} {msg}', ['label' => $this->label, 'method' => $method, 'url' => $url, 'status' => $status, 'msg' => $message]);
            $hint = match ($status) {
                401 => ' — vérifiez l\'identifiant et le jeton.',
                403 => ' — droits insuffisants.',
                404 => ' — ressource introuvable.',
                default => '',
            };
            throw new AtlassianException(sprintf('%s a répondu %d : %s%s', $this->label, $status, $message, $hint), $status);
        }

        if ('' === $content) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AtlassianException(sprintf('%s a renvoyé une réponse non JSON (URL de base incorrecte ?)', $this->label));
        }

        return \is_array($data) ? $data : [];
    }

    private function extractError(string $content): string
    {
        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return mb_substr(strip_tags($content), 0, 200);
        }
        $messages = array_merge(
            (array) ($data['errorMessages'] ?? []),
            array_values((array) ($data['errors'] ?? [])),
        );
        if (isset($data['message']) && \is_string($data['message'])) {
            $messages[] = $data['message'];
        }

        return implode(' ; ', array_filter($messages, 'is_string'));
    }

    private function isRefreshRequested(): bool
    {
        return (bool) $this->requestStack?->getMainRequest()?->query->getBoolean('refresh');
    }
}
