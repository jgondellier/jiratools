<?php

namespace App\LocalUpdate;

use Composer\CaBundle\CaBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge l'archive zip dans un fichier.
 *
 * Connexion : mêmes réglages que Jira / Confluence — proxy APP_HTTP_PROXY (sinon variables
 * HTTPS_PROXY / HTTP_PROXY), autorités publiques (liste Mozilla) + APP_HTTP_CAFILE (racine de
 * l'entreprise, PEM ou DER), vérification du certificat APP_HTTP_VERIFY_PEER.
 */
class ArchiveDownloader
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::APP_HTTP_PROXY)%')]
        private readonly ?string $proxy = null,
        #[Autowire('%env(default::APP_HTTP_CAFILE)%')]
        private readonly ?string $caFile = null,
        #[Autowire('%env(default::APP_HTTP_VERIFY_PEER)%')]
        string|bool|null $verifySsl = true,
        #[Autowire('%kernel.project_dir%/var/certs')]
        private readonly string $certDirectory = '',
    ) {
        // Variable absente ou vide : vérification active
        $this->verifySsl = null === $verifySsl || '' === $verifySsl || filter_var($verifySsl, FILTER_VALIDATE_BOOL);
    }

    private readonly bool $verifySsl;

    /**
     * @return int taille téléchargée (octets)
     */
    public function download(ArchiveRequest $request, string $destination): int
    {
        $options = ['headers' => $request->headers, 'max_redirects' => 10, 'timeout' => 60] + $this->connectionOptions();
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
        $options = ['verify_peer' => $this->verifySsl, 'verify_host' => $this->verifySsl];
        if ('' !== trim((string) $this->proxy)) {
            $options['proxy'] = trim($this->proxy);
        }
        if ($this->verifySsl) {
            $options['cafile'] = $this->caBundle();
        }

        return $options;
    }

    /** Autorités publiques + certificat de l'entreprise (PHP sous Windows n'en connaît souvent aucune) */
    private function caBundle(): string
    {
        $public = CaBundle::getBundledCaBundlePath();
        $extra = trim((string) $this->caFile);
        if ('' === $extra) {
            return $public;
        }
        if (!is_file($extra)) {
            throw new LocalUpdateException(sprintf('APP_HTTP_CAFILE : fichier introuvable (%s)', $extra));
        }
        $content = (string) file_get_contents($extra);
        if (!str_contains($content, '-----BEGIN CERTIFICATE-----')) {
            // Certificat binaire (DER, export Windows par défaut)
            $content = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($content), 64, "\n")."-----END CERTIFICATE-----\n";
        }
        $content = file_get_contents($public)."\n# ".$extra."\n".$content;
        $file = rtrim($this->certDirectory ?: sys_get_temp_dir(), '/\\').'/update_ca_'.md5($content).'.pem';
        if (!is_file($file)) {
            @mkdir(\dirname($file), 0775, true);
            file_put_contents($file, $content);
        }

        return $file;
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
