<?php

namespace App\Entity;

use App\Repository\StatusRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Seuils d'alerte : durée maximale qu'un ticket peut passer dans un statut.
 * Une règle sans projet s'applique à tous les projets.
 */
#[ORM\Entity(repositoryClass: StatusRuleRepository::class)]
class StatusRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom du statut Jira (insensible à la casse), ex : "Code Review" */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $statusName = '';

    /** Au-delà : alerte orange (en jours, décimales autorisées) */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $warningDays = 2;

    /** Au-delà : alerte rouge (en jours) */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $criticalDays = 4;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatusName(): string
    {
        return $this->statusName;
    }

    public function setStatusName(string $statusName): static
    {
        $this->statusName = trim($statusName);

        return $this;
    }

    public function getWarningDays(): float
    {
        return $this->warningDays;
    }

    public function setWarningDays(float $warningDays): static
    {
        $this->warningDays = $warningDays;

        return $this;
    }

    public function getCriticalDays(): float
    {
        return $this->criticalDays;
    }

    public function setCriticalDays(float $criticalDays): static
    {
        $this->criticalDays = $criticalDays;

        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    #[Assert\Callback]
    public function validateThresholds(ExecutionContextInterface $context): void
    {
        if ($this->criticalDays < $this->warningDays) {
            $context->buildViolation('Le seuil rouge doit être supérieur ou égal au seuil orange.')
                ->atPath('criticalDays')
                ->addViolation();
        }
    }
}
