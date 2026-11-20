<?php

namespace App\Release;

use App\Jira\Issue;

/**
 * Ticket d'un projet et l'endroit où se trouve son code : develop (pas encore taggé), tag(s) de version, ou nulle part.
 */
final class TrackedTicket
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @var array<int, array{level: string, message: string}> */
    public array $problems = [];

    /** @var string[] tags de version contenant le code, du plus récent au plus ancien */
    public array $tags = [];

    public bool $inDevelop = false;

    /**
     * @var array<int, array{repository: string, type: string, label: string, url: string, author: string, date: ?\DateTimeImmutable}>
     */
    public array $sources = [];

    public function __construct(
        public readonly string $key,
        public readonly ?Issue $issue,
        public readonly ?Issue $parent = null,
    ) {
    }

    public function isInCode(): bool
    {
        return $this->inDevelop || [] !== $this->tags;
    }

    /** Versions Jira du ticket, à défaut celles du parent (sous-tâche) */
    public function getVersions(): array
    {
        return $this->issue?->versionDetails ?: ($this->parent?->versionDetails ?? []);
    }

    /**
     * @param string[] $analysedTags tags lus dans GitLab (du plus récent au plus ancien)
     */
    public function evaluate(array $analysedTags, string $branch): self
    {
        $this->problems = [];
        $issue = $this->issue;
        if (null === $issue) {
            $this->add(self::ERROR, 'Introuvable dans Jira : clé erronée dans le commit, ticket supprimé ou non lisible avec ce compte.');

            return $this;
        }

        $versions = $this->getVersions();
        $names = array_map(fn (array $v) => $v['name'], $versions);
        $numbers = array_filter(array_map(fn (array $v) => VersionNumber::of($v['name']), $versions));
        $quoted = implode(', ', array_map(fn ($n) => '« '.$n.' »', $names));

        if ([] !== $this->tags) {
            $tagNumbers = array_map(VersionNumber::of(...), $this->tags);
            $tagList = implode(', ', $this->tags);
            if ([] === $versions) {
                $this->add(self::ERROR, sprintf('Livré dans le tag %s sans version Jira (fixVersion).', $tagList));
            } elseif ([] === array_intersect($numbers, $tagNumbers) && [] === array_filter($this->tags, fn ($t) => \in_array(mb_strtolower($t), array_map('mb_strtolower', $names), true))) {
                $this->add(self::ERROR, sprintf('Livré dans le tag %s mais la version Jira est %s.', $tagList, $quoted));
            }
            if (!$issue->isDone()) {
                $this->add(self::WARNING, sprintf('Livré dans le tag %s mais toujours « %s » dans Jira.', $this->tags[0], $issue->status));
            }

            return $this;
        }

        // Version Jira dont le tag existe déjà
        $tagged = [];
        foreach ($versions as $v) {
            foreach ($analysedTags as $tag) {
                if (VersionNumber::same($tag, $v['name'])) {
                    $tagged[$v['name']] = $tag;
                }
            }
        }

        if ($this->inDevelop) {
            if ([] === $versions) {
                $this->add(self::WARNING, sprintf('Dans %s sans version Jira (fixVersion) : avec quelle version partira-t-il ?', $branch));
            } elseif ([] !== $tagged) {
                $this->add(self::ERROR, sprintf('Version %s déjà taggée (%s) mais le code n\'est que dans %s : arrivé après le tag.', $quoted, implode(', ', $tagged), $branch));
            } elseif ([] === array_filter($versions, fn (array $v) => !$v['released'])) {
                $this->add(self::ERROR, sprintf('Version %s livrée dans Jira mais le code n\'est que dans %s.', $quoted, $branch));
            }
            if ($issue->isTodo()) {
                $this->add(self::WARNING, sprintf('Dans %s mais « %s » dans Jira.', $branch, $issue->status));
            }

            return $this;
        }

        // Aucun code trouvé
        if ([] !== $tagged) {
            $this->add(self::ERROR, sprintf('Dans la version %s mais aucun commit dans le tag %s.', $quoted, implode(', ', array_unique($tagged))));
        } elseif ([] !== $versions && [] === array_filter($versions, fn (array $v) => !$v['released'])) {
            $this->add(self::WARNING, sprintf('Version %s livrée dans Jira mais aucun tag correspondant parmi les tags lus.', $quoted));
        } elseif ($issue->isDone()) {
            $this->add(self::WARNING, sprintf('Terminé dans Jira mais aucun commit dans %s ni dans les tags lus.', $branch));
        }

        return $this;
    }

    public function add(string $level, string $message): void
    {
        $this->problems[] = ['level' => $level, 'message' => $message];
    }

    public function getLevel(): string
    {
        $levels = array_column($this->problems, 'level');

        return match (true) {
            \in_array(self::ERROR, $levels, true) => self::ERROR,
            \in_array(self::WARNING, $levels, true) => self::WARNING,
            default => self::OK,
        };
    }

    public function addSource(array $source): void
    {
        foreach ($this->sources as $existing) {
            if ($existing['url'] === $source['url'] && $existing['label'] === $source['label']) {
                return;
            }
        }
        $this->sources[] = $source;
    }
}
