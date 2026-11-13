<?php

namespace App\LocalUpdate;

use App\Entity\LocalApp;

/**
 * URL et en-têtes de téléchargement de l'archive zip d'un dépôt, selon l'hébergeur.
 */
final class ArchiveRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?array $basicAuth = null,
    ) {
    }

    public static function for(LocalApp $app, ?string $ref = null): self
    {
        $ref = trim($ref ?? '') ?: $app->getRef();
        $repository = trim($app->getRepository(), '/ ');
        $token = self::resolveToken($app->getToken());
        $headers = ['User-Agent' => 'jiratools-local-update'];

        switch ($app->getProvider()) {
            case LocalApp::PROVIDER_GITHUB:
                $repository = self::stripHostAndGit($repository);
                $api = rtrim($app->getServerUrl() ?: 'https://api.github.com', '/');
                $headers['Accept'] = 'application/vnd.github+json';
                if (null !== $token) {
                    $headers['Authorization'] = 'Bearer '.$token;
                }

                return new self(sprintf('%s/repos/%s/zipball/%s', $api, $repository, rawurlencode($ref)), $headers);

            case LocalApp::PROVIDER_GITLAB:
                $repository = self::stripHostAndGit($repository);
                $server = rtrim($app->getServerUrl() ?: 'https://gitlab.com', '/');
                if (null !== $token) {
                    $headers['PRIVATE-TOKEN'] = $token;
                }
                // Un identifiant numérique de projet est accepté tel quel
                $project = ctype_digit($repository) ? $repository : rawurlencode($repository);

                return new self(sprintf('%s/api/v4/projects/%s/repository/archive.zip?sha=%s', $server, $project, rawurlencode($ref)), $headers);

            case LocalApp::PROVIDER_BITBUCKET:
                $repository = self::stripHostAndGit($repository);
                $server = rtrim($app->getServerUrl() ?: 'https://bitbucket.org', '/');
                $basic = null;
                if (null !== $token) {
                    // « utilisateur:mot-de-passe-d'application » ou jeton d'accès (Bearer)
                    if (str_contains($token, ':')) {
                        $basic = explode(':', $token, 2);
                    } else {
                        $headers['Authorization'] = 'Bearer '.$token;
                    }
                }

                return new self(sprintf('%s/%s/get/%s.zip', $server, $repository, rawurlencode($ref)), $headers, $basic);

            case LocalApp::PROVIDER_URL:
                if (null !== $token) {
                    $headers['Authorization'] = 'Bearer '.$token;
                }

                return new self(str_replace(['{ref}', '{branch}'], rawurlencode($ref), $app->getRepository()), $headers);
        }

        throw new LocalUpdateException(sprintf('Hébergeur inconnu : %s', $app->getProvider()));
    }

    /** URL affichable (sans jeton) */
    public function describe(): string
    {
        $auth = isset($this->headers['Authorization']) || isset($this->headers['PRIVATE-TOKEN']) || null !== $this->basicAuth;

        return $this->url.($auth ? ' (authentifiée par jeton)' : ' (sans jeton)');
    }

    /** « env:NOM » lit la variable NOM (définie dans .env.local par exemple) */
    public static function resolveToken(?string $token): ?string
    {
        $token = trim((string) $token);
        if (str_starts_with($token, 'env:')) {
            $name = trim(substr($token, 4));
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            if (!\is_string($value) || '' === trim($value)) {
                throw new LocalUpdateException(sprintf('La variable d\'environnement %s (jeton) n\'est pas définie : ajoutez-la dans .env.local.', $name));
            }
            $token = trim($value);
        }

        return '' === $token ? null : $token;
    }

    /** Accepte aussi une URL complète du dépôt : https://github.com/owner/repo(.git) */
    private static function stripHostAndGit(string $repository): string
    {
        if (preg_match('#^(?:https?://|git@)[^/:]+[/:](.+)$#', $repository, $m)) {
            $repository = $m[1];
        }

        return preg_replace('/\.git$/', '', trim($repository, '/'));
    }
}
