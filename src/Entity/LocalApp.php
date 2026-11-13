<?php

namespace App\Entity;

use App\Repository\LocalAppRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Application installée sur le poste, mise à jour depuis une archive zip de son dépôt Git.
 */
#[ORM\Entity(repositoryClass: LocalAppRepository::class)]
class LocalApp
{
    public const PROVIDER_GITHUB = 'github';
    public const PROVIDER_GITLAB = 'gitlab';
    public const PROVIDER_BITBUCKET = 'bitbucket';
    public const PROVIDER_URL = 'url';

    public const DEFAULT_PRESERVED_PATHS = <<<'TXT'
        .env.local
        .env.*.local
        .git/
        var/
        vendor/
        node_modules/
        public/uploads/
        TXT;

    public const DEFAULT_COMMANDS = <<<'TXT'
        {composer} install --no-interaction --optimize-autoloader
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
        php bin/console cache:clear
        # php bin/console app:import-catalogue
        TXT;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::PROVIDER_GITHUB, self::PROVIDER_GITLAB, self::PROVIDER_BITBUCKET, self::PROVIDER_URL])]
    private string $provider = self::PROVIDER_GITHUB;

    /** owner/depot (GitHub, Bitbucket), groupe/sous-groupe/projet (GitLab) ou URL du zip avec {ref} */
    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    private string $repository = '';

    /** GitHub Enterprise (URL de l'API) ou GitLab auto-hébergé ; vide = github.com / gitlab.com */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: false)]
    private ?string $serverUrl = null;

    /** Branche, tag ou commit */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $ref = 'main';

    /** Jeton d'accès, ou env:NOM_VARIABLE pour le lire dans .env.local */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $token = null;

    /** Sous-dossier de l'archive à déployer (dépôt contenant plusieurs applications) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subdirectory = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    private string $targetDirectory = '';

    /** Chemins jamais écrasés ni supprimés, un par ligne */
    #[ORM\Column(type: 'text')]
    private string $preservedPaths = self::DEFAULT_PRESERVED_PATHS;

    /** Commandes lancées dans le répertoire du projet, une par ligne */
    #[ORM\Column(type: 'text')]
    private string $commands = self::DEFAULT_COMMANDS;

    /** Variables d'environnement des commandes (NOM=valeur, une par ligne) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $environment = null;

    /** Supprime les fichiers déployés la fois précédente et absents de la nouvelle archive */
    #[ORM\Column]
    private bool $deleteObsolete = true;

    /** Sauvegarde les fichiers remplacés ou supprimés avant la mise à jour */
    #[ORM\Column]
    private bool $backup = true;

    #[ORM\Column]
    private bool $stopOnError = true;

    /** @var list<string>|null Fichiers déployés lors de la dernière mise à jour */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $deployedFiles = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastVersion = null;

    public function __toString(): string
    {
        return $this->name;
    }

    public static function providers(): array
    {
        return [
            'GitHub' => self::PROVIDER_GITHUB,
            'GitLab' => self::PROVIDER_GITLAB,
            'Bitbucket' => self::PROVIDER_BITBUCKET,
            'URL du zip' => self::PROVIDER_URL,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): static
    {
        $this->provider = (string) $provider;

        return $this;
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    public function setRepository(?string $repository): static
    {
        $this->repository = trim((string) $repository);

        return $this;
    }

    public function getServerUrl(): ?string
    {
        return $this->serverUrl;
    }

    public function setServerUrl(?string $serverUrl): static
    {
        $this->serverUrl = '' === trim((string) $serverUrl) ? null : trim($serverUrl);

        return $this;
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function setRef(?string $ref): static
    {
        $this->ref = trim((string) $ref);

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): static
    {
        $this->token = '' === trim((string) $token) ? null : trim($token);

        return $this;
    }

    public function getSubdirectory(): ?string
    {
        return $this->subdirectory;
    }

    public function setSubdirectory(?string $subdirectory): static
    {
        $subdirectory = trim(str_replace('\\', '/', (string) $subdirectory), '/ ');
        $this->subdirectory = '' === $subdirectory ? null : $subdirectory;

        return $this;
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }

    public function setTargetDirectory(?string $targetDirectory): static
    {
        $this->targetDirectory = trim((string) $targetDirectory);

        return $this;
    }

    public function getPreservedPaths(): string
    {
        return $this->preservedPaths;
    }

    public function setPreservedPaths(?string $preservedPaths): static
    {
        $this->preservedPaths = (string) $preservedPaths;

        return $this;
    }

    /** @return list<string> */
    public function getPreservedPathList(): array
    {
        return self::lines($this->preservedPaths);
    }

    public function getCommands(): string
    {
        return $this->commands;
    }

    public function setCommands(?string $commands): static
    {
        $this->commands = (string) $commands;

        return $this;
    }

    /** @return list<string> Commandes actives (lignes vides et commentaires # ignorés) */
    public function getCommandList(): array
    {
        return self::lines($this->commands);
    }

    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    public function setEnvironment(?string $environment): static
    {
        $this->environment = '' === trim((string) $environment) ? null : $environment;

        return $this;
    }

    /** @return array<string, string> */
    public function getEnvironmentVariables(): array
    {
        $vars = [];
        foreach (self::lines((string) $this->environment) as $line) {
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $value = trim($value);
                if (\strlen($value) >= 2 && $value[0] === $value[-1] && \in_array($value[0], ['"', "'"], true)) {
                    $value = substr($value, 1, -1);
                }
                $vars[trim($name)] = $value;
            }
        }

        return $vars;
    }

    public function isDeleteObsolete(): bool
    {
        return $this->deleteObsolete;
    }

    public function setDeleteObsolete(bool $deleteObsolete): static
    {
        $this->deleteObsolete = $deleteObsolete;

        return $this;
    }

    public function isBackup(): bool
    {
        return $this->backup;
    }

    public function setBackup(bool $backup): static
    {
        $this->backup = $backup;

        return $this;
    }

    public function isStopOnError(): bool
    {
        return $this->stopOnError;
    }

    public function setStopOnError(bool $stopOnError): static
    {
        $this->stopOnError = $stopOnError;

        return $this;
    }

    /** @return list<string>|null */
    public function getDeployedFiles(): ?array
    {
        return $this->deployedFiles;
    }

    /** @param list<string>|null $deployedFiles */
    public function setDeployedFiles(?array $deployedFiles): static
    {
        $this->deployedFiles = $deployedFiles;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function getLastVersion(): ?string
    {
        return $this->lastVersion;
    }

    public function recordRun(LocalUpdateRun $run): static
    {
        $this->lastRunAt = $run->getStartedAt();
        $this->lastStatus = $run->getStatus();
        if (null !== $run->getVersion()) {
            $this->lastVersion = $run->getVersion();
        }

        return $this;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ('' !== $line && !str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
