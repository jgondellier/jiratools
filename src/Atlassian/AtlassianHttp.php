<?php

namespace App\Atlassian;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
        $result = $this->getMany(['_' => [$path, $query, $ttl]])['_'];
        if ($result instanceof AtlassianException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Plusieurs requêtes GET envoyées en parallèle (les réponses déjà en cache ne sont pas redemandées).
     *
     * @param array<string, array{0: string, 1?: array, 2?: ?int}> $requests clé => [chemin, paramètres, durée de cache]
     *
     * @return array<string, array|AtlassianException> clé => données, ou l'erreur de cette requête
     */
    public function getMany(array $requests): array
    {
        $results = [];
        $pending = [];
        $miss = new \stdClass();

        foreach ($requests as $key => $request) {
            [$path, $query, $ttl] = $request + [1 => [], 2 => null];
            $ttl ??= $this->cacheTtl;
            $cacheKey = 'atl_'.md5($this->label.$this->baseUrl.$path.serialize($query));

            if ($ttl <= 0 || $this->isRefreshRequested()) {
                $this->cache->delete($cacheKey);
            } else {
                $cached = $this->cache->get($cacheKey, function (ItemInterface $item, bool &$save) use ($miss) {
                    $save = false;

                    return $miss;
                });
                if ($cached !== $miss && \is_array($cached)) {
                    $results[$key] = $cached;
                    continue;
                }
            }

            try {
                // Les requêtes partent immédiatement ; les réponses sont lues ensuite
                $pending[$key] = [$this->start('GET', $path, ['query' => $query]), $cacheKey, $ttl];
            } catch (AtlassianException $e) {
                $results[$key] = $e;
            }
        }

        foreach ($pending as $key => [[$response, $url], $cacheKey, $ttl]) {
            try {
                $data = $this->finish($response, 'GET', $url);
                if ($ttl > 0) {
                    $this->cache->get($cacheKey, function (ItemInterface $item) use ($data, $ttl) {
                        $item->expiresAfter($ttl);

                        return $data;
                    });
                }
                $results[$key] = $data;
            } catch (AtlassianException $e) {
                $results[$key] = $e;
            }
        }

        // Conserve l'ordre des requêtes
        return array_replace(array_intersect_key($requests, $results), $results);
    }

    public function request(string $method, string $path, array $options = []): array
    {
        [$response, $url] = $this->start($method, $path, $options);

        return $this->finish($response, $method, $url);
    }

    /**
     * Lance la requête sans attendre la réponse.
     *
     * @return array{0: ResponseInterface, 1: string}
     */
    private function start(string $method, string $path, array $options): array
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

        $url = $this->getBaseUrl().'/'.ltrim($path, '/');

        try {
            return [$this->httpClient->request($method, $url, $options), $url];
        } catch (ExceptionInterface $e) {
            throw $this->unreachable($url, $e);
        }
    }

    /**
     * Attend la réponse et la décode.
     */
    private function finish(ResponseInterface $response, string $method, string $url): array
    {
        try {
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw $this->unreachable($url, $e);
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

    private function unreachable(string $url, ExceptionInterface $e): AtlassianException
    {
        $this->logger?->error('{label} injoignable : {msg}', ['label' => $this->label, 'msg' => $e->getMessage()]);

        return new AtlassianException(sprintf('%s injoignable (%s) : %s', $this->label, $url, $e->getMessage()), 0, $e);
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
