<?php

namespace App\Entity;

use App\Repository\DeveloperRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeveloperRepository::class)]
#[UniqueEntity('jiraAccountId', message: 'Ce compte Jira est déjà associé à un membre.')]
class Developer
{
    public const ROLES = [
        'Développeur' => 'dev',
        'Testeur / QA' => 'qa',
        'Lead / Tech lead' => 'lead',
        'Product Owner' => 'po',
        'Autre' => 'other',
    ];

    /** Libellé court de l'activité attendue, par rôle (badge de "Qui fait quoi") */
    public const WORK_LABELS = [
        'dev' => 'en dev',
        'qa' => 'en test',
        'lead' => 'en dev',
        'po' => 'en cours',
        'other' => 'en cours',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $displayName = '';

    /** accountId (Jira Cloud) ou nom d'utilisateur (Jira Server / Data Center) */
    #[ORM\Column(length: 128, unique: true)]
    #[Assert\NotBlank]
    private string $jiraAccountId = '';

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarUrl = null;

    #[ORM\Column(length: 20)]
    private string $role = 'dev';

    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'developers')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $team = null;

    /** Membre suivi dans le tableau "Qui fait quoi" */
    #[ORM\Column]
    private bool $active = true;

    /** Doit normalement toujours avoir un ticket en cours (alerte si aucun) */
    #[ORM\Column]
    private bool $expectedOnTicket = true;

    public function __toString(): string
    {
        return $this->displayName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getJiraAccountId(): string
    {
        return $this->jiraAccountId;
    }

    public function setJiraAccountId(string $jiraAccountId): static
    {
        $this->jiraAccountId = trim($jiraAccountId);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): static
    {
        $this->avatarUrl = $avatarUrl;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getRoleLabel(): string
    {
        return array_search($this->role, self::ROLES, true) ?: $this->role;
    }

    public function getWorkLabel(): string
    {
        return self::WORK_LABELS[$this->role] ?? 'en cours';
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        $this->team = $team;

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

    public function isExpectedOnTicket(): bool
    {
        return $this->expectedOnTicket;
    }

    public function setExpectedOnTicket(bool $expectedOnTicket): static
    {
        $this->expectedOnTicket = $expectedOnTicket;

        return $this;
    }

    public function getInitials(): string
    {
        $parts = preg_split('/\s+/', trim($this->displayName)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials ?: '?';
    }
}
