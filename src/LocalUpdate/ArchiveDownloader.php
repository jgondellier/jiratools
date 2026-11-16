<?php

namespace App\LocalUpdate;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge l'archive zip dans un fichier.
 *
 * Connexion : options communes du client HTTP de l'application (APP_HTTP_PROXY, APP_HTTP_CAFILE,
 * APP_HTTP_VERIFY_PEER…, voir config/packages/framework.yaml), sans limite de durée.
 */
class ArchiveDownloader
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return int taille téléchargée (octets)
     */
    public function download(ArchiveRequest $request, string $destination): int
    {
        $options = ['headers' => $request->headers, 'max_redirects' => 10, 'timeout' => 120] + $this->connectionOptions();
        if (null !== $request->basicAuth) {
            $options['auth_basic'] = $request->basicAuth;
        }

        $handle = @fopen($destination, 'wb');
        if (false === $handle) {
            throw new LocalUpdateException(sprintf('Impossible de créer le fichier %s', $destination));
        }

        try {
            $response = $this->httpClient->request('GET', $request->url, $options);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new LocalUpdateException(sprintf('Téléchargement refusé (HTTP %d) : %s%s', $status, $this->errorMessage($response->getContent(false)), match ($status) {
                    401 => ' — jeton absent ou invalide.',
                    403 => ' — droits insuffisants sur le dépôt (ou limite d\'appels atteinte sans jeton).',
                    404 => ' — dépôt, branche ou tag introuvable (un dépôt privé sans jeton répond aussi 404).',
                    default => '',
                }));
            }

            $size = 0;
            foreach ($this->httpClient->stream($response) as $chunk) {
                $size += fwrite($handle, $chunk->getContent());
            }
        } catch (ExceptionInterface $e) {
            $hint = str_contains($e->getMessage(), 'SSL certificate') ? ' — certificat non reconnu : indiquez le certificat racine de l\'entreprise dans APP_HTTP_CAFILE.'
                : (str_contains($e->getMessage(), 'resolve host') ? ' — réseau d\'entreprise : renseignez APP_HTTP_PROXY.' : '');
            throw new LocalUpdateException(sprintf('Téléchargement impossible : %s%s', $e->getMessage(), $hint), 0, $e);
        } finally {
            fclose($handle);
        }

        return $size;
    }

    public function connectionOptions(): array
    {
        return [];
    }

    private function errorMessage(string $content): string
    {
        $data = json_decode($content, true);
        if (\is_array($data) && \is_string($data['message'] ?? $data['error'] ?? null)) {
            return $data['message'] ?? $data['error'];
        }

        return mb_substr(trim(strip_tags($content)), 0, 200) ?: 'pas de détail';
    }
}
