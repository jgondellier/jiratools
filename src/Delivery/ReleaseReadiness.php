<?php

namespace App\Delivery;

use App\Jira\Issue;
use App\Service\IssueView;

/**
 * État de préparation d'une version à livrer : tickets, avancement, alertes.
 */
final class ReleaseReadiness
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @var array<string, IssueView> clé du ticket => ticket */
    public array $issues = [];

    /** @var array<string, array<int, array{level: string, message: string}>> clé du ticket => alertes */
    public array $issueAlerts = [];

    /** @var array<int, array{level: string, message: string}> alertes sur la version elle-même */
    public array $alerts = [];

    /** @var array<string, array<int, array{issue: Issue, version: ?string, date: ?\DateTimeImmutable}>> ticket WS => mes tickets qui en dépendent */
    public array $requiredBy = [];

    public function __construct(
        public readonly string $projectKey,
        public readonly string $versionId,
        public readonly string $name,
        public readonly bool $isWs,
        public readonly ?\DateTimeImmutable $jiraDate,
        public readonly ?\DateTimeImmutable $confluenceDate,
        public readonly bool $inConfluence,
        public readonly ?int $daysLeft,
        public readonly ?string $url = null,
        public readonly array $otherComponents = [],
        /** Provenance de la date Confluence : ligne, méthode (link / name / text), texte du lien */
        public readonly ?array $source = null,
    ) {
    }

    /** Date de livraison retenue : date de publication Jira (prioritaire), sinon calendrier Confluence */
    public function getDate(): ?\DateTimeImmutable
    {
        return $this->jiraDate ?? $this->confluenceDate;
    }

    /** Les deux dates existent et diffèrent */
    public function hasDateGap(): bool
    {
        return $this->jiraDate && $this->confluenceDate && $this->jiraDate->format('Y-m-d') !== $this->confluenceDate->format('Y-m-d');
    }

    /** Explication de la date affichée */
    public function getDateSourceLabel(): string
    {
        $confluence = null;
        if ($this->source) {
            $row = $this->source['row'];
            $how = match ($this->source['method']) {
                'link' => sprintf('lien vers la version Jira « %s »', $this->source['linkText']),
                'name' => 'nom de la version',
                default => 'texte de la cellule',
            };
            $confluence = $this->confluenceDate
                ? sprintf('Confluence ligne %d : %s (%s)', $row->number, $this->confluenceDate->format('d/m/Y'), $how)
                : sprintf('Confluence ligne %d : date illisible (« %s »)', $row->number, $row->rawDate);
        }

        if ($this->jiraDate) {
            return 'Date de publication Jira'.($confluence ? ' — '.$confluence : ' — absente du calendrier Confluence');
        }
        if ($this->confluenceDate) {
            return 'Pas de date dans Jira — '.$confluence;
        }

        return 'Aucune date'.($confluence ? ' — '.$confluence : '');
    }

    public function isOverdue(): bool
    {
        return null !== $this->getDate() && $this->getDate() < new \DateTimeImmutable('today');
    }

    public function addAlert(string $level, string $message): void
    {
        $this->alerts[] = ['level' => $level, 'message' => $message];
    }

    public function addIssueAlert(Issue $issue, string $level, string $message): void
    {
        $this->issueAlerts[$issue->key][] = ['level' => $level, 'message' => $message];
    }

    public function getLevel(): string
    {
        $levels = array_column($this->alerts, 'level');
        foreach ($this->issueAlerts as $alerts) {
            $levels = array_merge($levels, array_column($alerts, 'level'));
        }

        return match (true) {
            \in_array(self::ERROR, $levels, true) => self::ERROR,
            \in_array(self::WARNING, $levels, true) => self::WARNING,
            default => self::OK,
        };
    }

    public function getIssueLevel(string $key): string
    {
        $levels = array_column($this->issueAlerts[$key] ?? [], 'level');

        return \in_array(self::ERROR, $levels, true) ? self::ERROR : (\in_array(self::WARNING, $levels, true) ? self::WARNING : self::OK);
    }

    /** @return array{new: int, indeterminate: int, done: int} */
    public function getCounts(): array
    {
        $counts = ['new' => 0, 'indeterminate' => 0, 'done' => 0];
        foreach ($this->issues as $view) {
            ++$counts[$view->issue->statusCategory];
        }

        return $counts;
    }

    public function getTotal(): int
    {
        return \count($this->issues);
    }

    /** Avancement en % (points si estimés, sinon nombre de tickets) */
    public function getProgress(): int
    {
        $points = array_sum(array_map(fn (IssueView $v) => $v->issue->storyPoints ?? 0, $this->issues));
        if ($points > 0) {
            $done = array_sum(array_map(fn (IssueView $v) => $v->issue->isDone() ? ($v->issue->storyPoints ?? 0) : 0, $this->issues));

            return (int) round($done / $points * 100);
        }

        return $this->getTotal() > 0 ? (int) round($this->getCounts()['done'] / $this->getTotal() * 100) : 0;
    }

    public function getRemainingPoints(): float
    {
        return array_sum(array_map(fn (IssueView $v) => $v->issue->isDone() ? 0 : ($v->issue->storyPoints ?? 0), $this->issues));
    }

    /**
     * Tickets triés : alertes rouges, oranges, puis le reste ; terminés à la fin.
     *
     * @return IssueView[]
     */
    public function getSortedIssues(): array
    {
        $rank = [self::ERROR => 0, self::WARNING => 1, self::OK => 2];
        $issues = $this->issues;
        usort($issues, fn (IssueView $a, IssueView $b) => [(int) $a->issue->isDone(), $rank[$this->getIssueLevel($a->issue->key)], $a->issue->key]
            <=> [(int) $b->issue->isDone(), $rank[$this->getIssueLevel($b->issue->key)], $b->issue->key]);

        return $issues;
    }

    public function countIssuesInAlert(string $level): int
    {
        return \count(array_filter(array_keys($this->issueAlerts), fn (string $key) => $this->getIssueLevel($key) === $level));
    }
}
