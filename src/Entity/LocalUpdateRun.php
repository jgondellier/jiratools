<?php

namespace App\Entity;

use App\Repository\LocalUpdateRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une exécution de mise à jour (journal compris).
 */
#[ORM\Entity(repositoryClass: LocalUpdateRunRepository::class)]
#[ORM\Index(columns: ['started_at'])]
class LocalUpdateRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SIMULATED = 'simulated';

    /** Taille maximale du journal conservé (octets) */
    private const MAX_LOG = 2_000_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LocalApp::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LocalApp $app;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RUNNING;

    /** Branche / tag demandé */
    #[ORM\Column(length: 255)]
    private string $ref;

    /** Version effectivement déployée (dossier racine de l'archive, contient le commit) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(length: 20)]
    private string $trigger = 'web';

    #[ORM\Column(type: 'text')]
    private string $log = '';

    #[ORM\Column(type: 'json')]
    private array $summary = [];

    public function __construct(LocalApp $app, string $ref, string $trigger = 'web')
    {
        $this->app = $app;
        $this->ref = $ref;
        $this->trigger = $trigger;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApp(): LocalApp
    {
        return $this->app;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->finishedAt ? $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp() : null;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function finish(string $status): static
    {
        $this->status = $status;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getLog(): string
    {
        return $this->log;
    }

    public function setLog(string $log): static
    {
        if (\strlen($log) > self::MAX_LOG) {
            $log = "[… début du journal tronqué …]\n".substr($log, -self::MAX_LOG);
        }
        $this->log = $log;

        return $this;
    }

    public function getSummary(): array
    {
        return $this->summary;
    }

    public function setSummary(array $summary): static
    {
        $this->summary = $summary;

        return $this;
    }
}
