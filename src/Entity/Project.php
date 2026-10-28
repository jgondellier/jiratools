<?php

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un projet applicatif. Plusieurs projets peuvent partager le même projet Jira
 * (backlog commune) : ils sont alors distingués par leurs composants Jira.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project
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

    /** Clé du projet Jira (ex : APP) */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[A-Z][A-Z0-9_]+$/', message: 'Clé Jira en majuscules attendue (ex : APP)')]
    private string $jiraProjectKey = '';

    /** Identifiant du board Scrum (pour les sprints) */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $jiraBoardId = null;

    /** @var string[] Composants Jira identifiant ce projet dans la backlog */
    #[ORM\Column(type: 'json')]
    private array $components = [];

    /** Filtre JQL additionnel optionnel (ex : "type != Epic") */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $extraJql = null;

    /** Espace Confluence par défaut (pour publier la release note) */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $confluenceSpaceKey = null;

    /** Page parente Confluence sous laquelle publier les release notes */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $releaseNoteParentPageId = null;

    #[ORM\Column]
    private bool $active = true;

    /** @var Collection<int, Team> */
    #[ORM\ManyToMany(targetEntity: Team::class, inversedBy: 'projects')]
    private Collection $teams;

    /** @var Collection<int, ConfluencePage> */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ConfluencePage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $confluencePages;

    public function __construct()
    {
        $this->teams = new ArrayCollection();
        $this->confluencePages = new ArrayCollection();
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

    public function getJiraProjectKey(): string
    {
        return $this->jiraProjectKey;
    }

    public function setJiraProjectKey(string $jiraProjectKey): static
    {
        $this->jiraProjectKey = strtoupper(trim($jiraProjectKey));

        return $this;
    }

    public function getJiraBoardId(): ?int
    {
        return $this->jiraBoardId;
    }

    public function setJiraBoardId(?int $jiraBoardId): static
    {
        $this->jiraBoardId = $jiraBoardId;

        return $this;
    }

    /** @return string[] */
    public function getComponents(): array
    {
        return $this->components;
    }

    /** @param string[] $components */
    public function setComponents(array $components): static
    {
        $this->components = array_values(array_unique(array_filter(array_map('trim', $components), 'strlen')));

        return $this;
    }

    /** Version texte (une valeur par ligne ou séparée par des virgules) pour les formulaires */
    public function getComponentsText(): string
    {
        return implode("\n", $this->components);
    }

    public function setComponentsText(?string $text): static
    {
        return $this->setComponents(preg_split('/[\r\n,;]+/', (string) $text) ?: []);
    }

    public function getExtraJql(): ?string
    {
        return $this->extraJql;
    }

    public function setExtraJql(?string $extraJql): static
    {
        $this->extraJql = $extraJql;

        return $this;
    }

    public function getConfluenceSpaceKey(): ?string
    {
        return $this->confluenceSpaceKey;
    }

    public function setConfluenceSpaceKey(?string $confluenceSpaceKey): static
    {
        $this->confluenceSpaceKey = $confluenceSpaceKey;

        return $this;
    }

    public function getReleaseNoteParentPageId(): ?string
    {
        return $this->releaseNoteParentPageId;
    }

    public function setReleaseNoteParentPageId(?string $releaseNoteParentPageId): static
    {
        $this->releaseNoteParentPageId = $releaseNoteParentPageId;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /** @return Collection<int, Team> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(Team $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
        }

        return $this;
    }

    public function removeTeam(Team $team): static
    {
        $this->teams->removeElement($team);

        return $this;
    }

    /** @return Collection<int, ConfluencePage> */
    public function getConfluencePages(): Collection
    {
        return $this->confluencePages;
    }

    public function addConfluencePage(ConfluencePage $page): static
    {
        if (!$this->confluencePages->contains($page)) {
            $this->confluencePages->add($page);
            $page->setProject($this);
        }

        return $this;
    }

    public function removeConfluencePage(ConfluencePage $page): static
    {
        $this->confluencePages->removeElement($page);

        return $this;
    }
}
