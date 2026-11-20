<?php

namespace App\LocalUpdate;

use Composer\CaBundle\CaBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge l'archive zip dans un fichier.
 *
 * Connexion : réglages propres à la mise à jour, sinon ceux de Jira / Confluence / GitLab :
 *  - proxy : APP_UPDATE_PROXY (« aucun » = connexion directe), sinon APP_HTTP_PROXY, sinon HTTPS_PROXY / HTTP_PROXY ;
 *  - certificat de l'entreprise (PEM ou DER), ajouté aux autorités publiques (liste Mozilla) :
 *    APP_UPDATE_CAFILE, sinon APP_HTTP_CAFILE ;
 *  - vérification du certificat : APP_UPDATE_VERIFY_SSL, sinon APP_HTTP_VERIFY_PEER.
 */
class ArchiveDownloader
{
    /** Valeurs de APP_UPDATE_PROXY qui imposent une connexion directe (sans proxy) */
    private const NO_PROXY_VALUES = ['aucun', 'none', 'direct', '0', '-'];

    private readonly ?string $proxy;
    private readonly string $proxySource;
    private readonly bool $direct;
    private readonly ?string $caFile;
    private readonly string $caSource;
    private readonly bool $verifySsl;
    private readonly string $verifySource;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::APP_HTTP_PROXY)%')]
        ?string $proxy = null,
        #[Autowire('%env(default::APP_HTTP_CAFILE)%')]
        ?string $caFile = null,
        #[Autowire('%env(default::APP_HTTP_VERIFY_PEER)%')]
        string|bool|null $verifySsl = true,
        #[Autowire('%kernel.project_dir%/var/certs')]
        private readonly string $certDirectory = '',
        #[Autowire('%env(default::APP_UPDATE_PROXY)%')]
        ?string $updateProxy = null,
        #[Autowire('%env(default::APP_UPDATE_CAFILE)%')]
        ?string $updateCaFile = null,
        #[Autowire('%env(default::APP_UPDATE_VERIFY_SSL)%')]
        string|bool|null $updateVerifySsl = null,
    ) {
        // Réglage propre à la mise à jour s'il est renseigné, sinon réglage commun APP_HTTP_*
        $updateProxy = trim((string) $updateProxy);
        $this->direct = \in_array(mb_strtolower($updateProxy), self::NO_PROXY_VALUES, true);
        if ('' !== $updateProxy) {
            [$this->proxy, $this->proxySource] = [$this->direct ? null : $updateProxy, 'APP_UPDATE_PROXY'];
        } else {
            [$this->proxy, $this->proxySource] = [trim((string) $proxy) ?: null, 'APP_HTTP_PROXY'];
        }

        $updateCaFile = trim((string) $updateCaFile);
        [$this->caFile, $this->caSource] = '' !== $updateCaFile
            ? [$updateCaFile, 'APP_UPDATE_CAFILE']
            : [trim((string) $caFile) ?: null, 'APP_HTTP_CAFILE'];

        // Variable absente ou vide : vérification active
        $updateVerifySsl = \is_string($updateVerifySsl) ? trim($updateVerifySsl) : $updateVerifySsl;
        if (null !== $updateVerifySsl && '' !== $updateVerifySsl) {
            [$this->verifySsl, $this->verifySource] = [filter_var($updateVerifySsl, FILTER_VALIDATE_BOOL), 'APP_UPDATE_VERIFY_SSL'];
        } else {
            [$this->verifySsl, $this->verifySource] = [null === $verifySsl || '' === $verifySsl || filter_var($verifySsl, FILTER_VALIDATE_BOOL), 'APP_HTTP_VERIFY_PEER'];
        }
    }

    /** Redirections suivies au plus (GitHub : api.github.com → codeload.github.com) */
    private const MAX_REDIRECTS = 10;

    /**
     * Les redirections sont suivies ici, une par une, pour savoir quel serveur répond ou bloque :
     * un proxy d'entreprise laisse souvent passer api.github.com mais refuse codeload.github.com.
     *
     * @return int taille téléchargée (octets)
     */
    public function download(ArchiveRequest $request, string $destination): int
    {
        $url = $request->url;
        $headers = $request->headers;
        $basicAuth = $request->basicAuth;
        $hops = [];

        $handle = @fopen($destination, 'wb');
        if (false === $handle) {
            throw new LocalUpdateException(sprintf('Impossible de créer le fichier %s', $destination));
        }

        try {
            for ($redirects = 0; ; ++$redirects) {
                $hops[] = self::host($url);
                $options = ['headers' => $headers, 'max_redirects' => 0, 'timeout' => 60] + $this->connectionOptions();
                if (null !== $basicAuth) {
                    $options['auth_basic'] = $basicAuth;
                }

                try {
                    $response = $this->httpClient->request('GET', $url, $options);
                    $status = $response->getStatusCode();
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                } catch (ExceptionInterface $e) {
                    throw new LocalUpdateException($this->transportError($e, $hops), 0, $e);
                }

                if ($status >= 300 && $status < 400 && null !== $location) {
                    if ($redirects >= self::MAX_REDIRECTS) {
                        throw new LocalUpdateException(sprintf('Téléchargement impossible : trop de redirections (%s).', implode(' → ', $hops)));
                    }
                    $next = self::resolve($url, $location);
                    // Le jeton n'est jamais envoyé à un autre serveur que celui du dépôt
                    if (self::host($next) !== self::host($url)) {
                        unset($headers['Authorization'], $headers['PRIVATE-TOKEN']);
                        $basicAuth = null;
                    }
                    $url = $next;
                    continue;
                }
                break;
            }

            if ($status >= 400) {
                throw new LocalUpdateException(sprintf('Téléchargement refusé par %s (HTTP %d) : %s%s', end($hops), $status, $this->errorMessage($response->getContent(false)), match ($status) {
                    401 => ' — jeton absent ou invalide.',
                    403 => ' — droits insuffisants sur le dépôt (ou limite d\'appels atteinte sans jeton).',
                    404 => ' — dépôt, branche ou tag introuvable (un dépôt privé sans jeton répond aussi 404).',
                    default => '',
                }));
            }

            $size = 0;
            try {
                foreach ($this->httpClient->stream($response) as $chunk) {
                    $size += fwrite($handle, $chunk->getContent());
                }
            } catch (ExceptionInterface $e) {
                throw new LocalUpdateException($this->transportError($e, $hops), 0, $e);
            }
        } finally {
            fclose($handle);
        }

        return $size;
    }

    /** Message d'une erreur réseau : serveur en cause et piste de correction */
    private function transportError(ExceptionInterface $e, array $hops): string
    {
        $message = $e->getMessage();
        $host = end($hops);
        $via = \count($hops) > 1 ? sprintf(' (redirigé par %s)', implode(' → ', \array_slice($hops, 0, -1))) : '';
        $proxy = $this->describeProxy();

        if (preg_match('/(?:CONNECT tunnel failed, response|HTTP code) (\d{3})/i', $message, $m)) {
            $hint = match ((int) $m[1]) {
                407 => sprintf(' — le proxy %s demande une authentification : indiquez l\'identifiant et le mot de passe dans APP_UPDATE_PROXY (ou APP_HTTP_PROXY), ex. http://identifiant:mot-de-passe@proxy:8080 (caractères spéciaux encodés : @ → %%40).', $proxy),
                403 => sprintf(' — le proxy %s refuse l\'accès à %s : demandez son ouverture au support informatique%s, ou téléchargez depuis un dépôt interne (GitLab de l\'entreprise, ou « URL du zip » dans les paramètres).', $proxy, $host, 'codeload.github.com' === $host ? ' (GitHub fournit les zip depuis codeload.github.com, en plus de api.github.com)' : ''),
                default => sprintf(' — le proxy %s a refusé la connexion à %s (réponse %s).', $proxy, $host, $m[1]),
            };
        } elseif (preg_match('/tunnel|CONNECT|proxy/i', $message)) {
            $hint = sprintf(' — échec de la connexion par le proxy %s : vérifiez %s (l\'adresse commence en général par http://, même pour les sites en https://).', $proxy, $this->proxySource);
        } elseif (str_contains($message, 'SSL certificate') || str_contains($message, 'certificate')) {
            $hint = null !== $this->caFile
                ? sprintf(' — certificat non reconnu avec %s (%s) : essayez l\'autre certificat de l\'entreprise dans APP_UPDATE_CAFILE (utilisé seulement pour la mise à jour).', $this->caSource, $this->caFile)
                : ' — certificat non reconnu : indiquez le certificat racine de l\'entreprise dans APP_UPDATE_CAFILE (mise à jour seulement) ou APP_HTTP_CAFILE.';
        } elseif (str_contains($message, 'resolve host')) {
            $hint = $this->direct
                ? ' — APP_UPDATE_PROXY impose une connexion directe : indiquez le proxy de l\'entreprise.'
                : ' — réseau d\'entreprise : renseignez APP_UPDATE_PROXY (mise à jour seulement) ou APP_HTTP_PROXY.';
        } else {
            $hint = '';
        }

        return sprintf('Téléchargement impossible depuis %s%s : %s%s', $host, $via, $message, $hint);
    }

    /** Proxy utilisé, sans identifiant ni mot de passe */
    public function describeProxy(): string
    {
        if ($this->direct) {
            return 'aucun : connexion directe (APP_UPDATE_PROXY)';
        }
        $proxy = trim((string) $this->proxy);
        $source = $this->proxySource;
        if ('' === $proxy) {
            foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'] as $name) {
                $value = getenv($name);
                if (\is_string($value) && '' !== trim($value)) {
                    [$proxy, $source] = [trim($value), $name];
                    break;
                }
            }
        }
        if ('' === $proxy) {
            return '(aucun proxy paramétré)';
        }

        return sprintf('%s (%s)', preg_replace('#//[^/@]*@#', '//', $proxy), $source);
    }

    /** Certificats et vérification utilisés pour le téléchargement */
    public function describeCertificates(): string
    {
        if (!$this->verifySsl) {
            return sprintf('certificat du serveur NON vérifié (%s)', $this->verifySource);
        }
        if (null === $this->caFile) {
            return 'autorités publiques (liste Mozilla)';
        }

        return sprintf('autorités publiques + %s (%s)%s', $this->caFile, $this->caSource, is_file($this->caFile) ? '' : ' — fichier introuvable !');
    }

    /** Le certificat paramétré existe-t-il (ou aucun n'est paramétré) ? */
    public function isCertificateReadable(): bool
    {
        return null === $this->caFile || !$this->verifySsl || is_file($this->caFile);
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $origin = parse_url($base, PHP_URL_SCHEME).'://'.parse_url($base, PHP_URL_HOST).(null !== parse_url($base, PHP_URL_PORT) ? ':'.parse_url($base, PHP_URL_PORT) : '');
        if (str_starts_with($location, '//')) {
            return parse_url($base, PHP_URL_SCHEME).':'.$location;
        }

        return $origin.(str_starts_with($location, '/') ? '' : '/').$location;
    }

    public function connectionOptions(): array
    {
        $options = ['verify_peer' => $this->verifySsl, 'verify_host' => $this->verifySsl];
        if ($this->direct) {
            // Ignore aussi le proxy commun APP_HTTP_PROXY appliqué par défaut au client HTTP
            $options['no_proxy'] = '*';
        } elseif (null !== $this->proxy) {
            $options['proxy'] = $this->proxy;
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
            throw new LocalUpdateException(sprintf('%s : fichier introuvable (%s)', $this->caSource, $extra));
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
