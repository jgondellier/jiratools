<?php

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Couleur d'affichage (hexadécimal) */
    #[ORM\Column(length: 7)]
    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/', message: 'Couleur hexadécimale attendue (ex : #0d6efd)')]
    private string $color = '#0d6efd';

    /** Nombre maximum de tickets "en cours" par développeur avant alerte */
    #[ORM\Column]
    #[Assert\Positive]
    private int $wipLimit = 2;

    /** @var Collection<int, Developer> */
    #[ORM\OneToMany(mappedBy: 'team', targetEntity: Developer::class)]
    private Collection $developers;

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class, mappedBy: 'teams')]
    private Collection $projects;

    public function __construct()
    {
        $this->developers = new ArrayCollection();
        $this->projects = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getWipLimit(): int
    {
        return $this->wipLimit;
    }

    public function setWipLimit(int $wipLimit): static
    {
        $this->wipLimit = $wipLimit;

        return $this;
    }

    /** @return Collection<int, Developer> */
    public function getDevelopers(): Collection
    {
        return $this->developers;
    }

    /** @return Developer[] */
    public function getActiveDevelopers(): array
    {
        $developers = array_values($this->developers->filter(fn (Developer $d) => $d->isActive())->toArray());
        usort($developers, fn (Developer $a, Developer $b) => strnatcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $developers;
    }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection
    {
        return $this->projects;
    }
}
