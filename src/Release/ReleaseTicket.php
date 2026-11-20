<?php

namespace App\Release;

use App\Jira\Issue;

/**
 * Ticket Jira cité dans le code livré (merge requests / commits de la branche develop) et son contrôle.
 */
final class ReleaseTicket
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @var array<int, array{level: string, message: string}> */
    public array $problems = [];

    /**
     * @param array<int, array{repository: string, type: string, label: string, url: string, author: string, date: ?\DateTimeImmutable}> $sources
     */
    public function __construct(
        public readonly string $key,
        public readonly ?Issue $issue,
        public readonly ?Issue $parent = null,
        public array $sources = [],
    ) {
    }

    /**
     * Le ticket est-il à jour dans Jira pour une livraison du code ?
     *
     * @param array<string, string> $targetVersions   identifiant => nom des versions de la MEP (vide : pas de contrôle de version)
     * @param string[]              $expectedStatuses statuts attendus (vide : catégorie "Terminé")
     */
    public function evaluate(array $targetVersions, array $expectedStatuses): self
    {
        $this->problems = [];
        $issue = $this->issue;
        if (null === $issue) {
            $this->add(self::ERROR, 'Introuvable dans Jira : clé erronée dans le commit, ticket supprimé ou non lisible avec ce compte.');

            return $this;
        }

        // Statut
        if ([] !== $expectedStatuses) {
            $expected = array_map('mb_strtolower', $expectedStatuses);
            if (!\in_array(mb_strtolower($issue->status), $expected, true)) {
                $this->add($issue->isTodo() ? self::ERROR : self::WARNING, sprintf('Statut « %s » : attendu %s.', $issue->status, self::quoteList($expectedStatuses, ' ou ')));
            }
        } elseif ($issue->isTodo()) {
            $this->add(self::ERROR, sprintf('Statut « %s » : le code est dans la branche livrée mais le ticket n\'est pas commencé dans Jira.', $issue->status));
        } elseif ($issue->isInProgress()) {
            $this->add(self::WARNING, sprintf('Toujours « %s » dans Jira alors que le code est dans la branche livrée.', $issue->status));
        }

        // Version corrective : celle du ticket, à défaut celle du parent (sous-tâche)
        $versions = $issue->versionDetails ?: ($this->parent?->versionDetails ?? []);
        $inherited = [] === $issue->versionDetails && [] !== $versions;
        $names = array_map(fn (array $v) => $v['name'], $versions);

        if ([] === $targetVersions) {
            if ([] === $versions) {
                $this->add(self::WARNING, 'Aucune version corrective (fixVersion).');
            }

            return $this;
        }

        $ids = array_map(fn (array $v) => (string) ($v['id'] ?? ''), $versions);
        if ([] !== array_intersect($ids, array_keys($targetVersions))) {
            return $this;
        }

        $target = self::quoteList(array_unique(array_values($targetVersions)), ' / ');
        $unreleased = array_filter($versions, fn (array $v) => !$v['released']);
        $whose = $inherited ? sprintf(' (version du ticket parent %s)', $this->parent->key) : '';
        if ([] !== $unreleased) {
            $this->add(self::ERROR, sprintf('Prévu dans %s%s, pas dans %s : le code fait pourtant partie de cette livraison.', self::quoteList(array_map(fn ($v) => $v['name'], $unreleased), ', '), $whose, $target));
        } elseif ([] !== $versions) {
            $this->add(self::WARNING, sprintf('Version %s déjà livrée%s : ajouter %s si ce code est une suite ou un correctif.', self::quoteList($names, ', '), $whose, $target));
        } else {
            $this->add(self::ERROR, sprintf('Aucune version corrective (fixVersion) : ajouter %s.', $target));
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

    /** Ajoute une provenance (merge request ou commit), sans doublon */
    public function addSource(array $source): void
    {
        foreach ($this->sources as $existing) {
            if ($existing['url'] === $source['url'] && $existing['label'] === $source['label']) {
                return;
            }
        }
        $this->sources[] = $source;
    }

    private static function quoteList(array $values, string $glue): string
    {
        return implode($glue, array_map(fn ($v) => '« '.$v.' »', $values));
    }
}
