<?php

namespace App\Entity;

use App\Repository\ConfluencePageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConfluencePageRepository::class)]
class ConfluencePage
{
    public const CATEGORIES = [
        'Spécifications' => 'specs',
        'Architecture / technique' => 'tech',
        'Documentation utilisateur' => 'doc',
        'Release notes' => 'release',
        'Comptes-rendus / rituels' => 'meeting',
        'Exploitation / run' => 'ops',
        'Autre' => 'other',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'confluencePages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title = '';

    /** Identifiant de la page Confluence (dans l'URL : /pages/123456/...) */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Regex('/^\d+$/', message: 'Identifiant numérique attendu')]
    private ?string $pageId = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url]
    private ?string $url = null;

    #[ORM\Column(length: 20)]
    private string $category = 'other';

    /** Alerte si la page n'a pas été modifiée depuis N jours (vide = pas d'alerte) */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $maxAgeDays = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getPageId(): ?string
    {
        return $this->pageId;
    }

    public function setPageId(?string $pageId): static
    {
        $this->pageId = $pageId;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;
        // Déduit l'identifiant de page depuis l'URL si possible
        if (null === $this->pageId && $url && preg_match('#/pages/(\d+)#', $url, $m)) {
            $this->pageId = $m[1];
        } elseif (null === $this->pageId && $url && preg_match('#[?&]pageId=(\d+)#', $url, $m)) {
            $this->pageId = $m[1];
        }

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getCategoryLabel(): string
    {
        return array_search($this->category, self::CATEGORIES, true) ?: $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getMaxAgeDays(): ?int
    {
        return $this->maxAgeDays;
    }

    public function setMaxAgeDays(?int $maxAgeDays): static
    {
        $this->maxAgeDays = $maxAgeDays;

        return $this;
    }
}
