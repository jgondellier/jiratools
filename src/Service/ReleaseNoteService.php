<?php

namespace App\Service;

use App\Entity\Project;
use App\Jira\Issue;
use App\Jira\JiraClient;

/**
 * Release note : tickets d'une version (fixVersion) ou d'un sprint, regroupés par étiquette (sujet).
 */
class ReleaseNoteService
{
    public const NO_LABEL = 'Autres évolutions';

    /** @var string[] */
    private array $ignoredLabels;

    public function __construct(
        private readonly JiraClient $jira,
        string $ignoredLabels = '',
    ) {
        $this->ignoredLabels = array_map('mb_strtolower', array_filter(array_map('trim', explode(',', $ignoredLabels))));
    }

    public function buildJql(Project $project, ?string $version, ?int $sprintId, bool $onlyDone): string
    {
        $parts = [ProjectScope::jql($project), 'issuetype not in subTaskIssueTypes()'];
        if ($version) {
            $parts[] = 'fixVersion = '.JiraClient::quote($version);
        }
        if ($sprintId) {
            $parts[] = 'sprint = '.$sprintId;
        }
        if ($onlyDone) {
            $parts[] = 'statusCategory = Done';
        }

        return implode(' AND ', $parts).' ORDER BY issuetype ASC, key ASC';
    }

    /**
     * @return array<string, Issue[]> sujet => tickets (un ticket multi-étiquettes apparaît dans chaque sujet)
     */
    public function group(array $issues): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            $labels = array_filter($issue->labels, fn (string $l) => !\in_array(mb_strtolower($l), $this->ignoredLabels, true));
            foreach ($labels ?: [self::NO_LABEL] as $label) {
                $groups[$label][] = $issue;
            }
        }
        uksort($groups, function (string $a, string $b) {
            if (self::NO_LABEL === $a) {
                return 1;
            }
            if (self::NO_LABEL === $b) {
                return -1;
            }

            return strnatcasecmp($a, $b);
        });
        foreach ($groups as &$list) {
            // Évolutions puis corrections
            usort($list, fn (Issue $x, Issue $y) => [(int) $x->isBug(), $x->key] <=> [(int) $y->isBug(), $y->key]);
        }

        return $groups;
    }

    /**
     * @return array{jql: string, issues: Issue[], groups: array<string, Issue[]>}
     */
    public function generate(Project $project, ?string $version, ?int $sprintId, bool $onlyDone = true): array
    {
        $jql = $this->buildJql($project, $version, $sprintId, $onlyDone);
        $issues = $this->jira->search($jql, 1000);

        return ['jql' => $jql, 'issues' => $issues, 'groups' => $this->group($issues)];
    }

    public static function humanizeLabel(string $label): string
    {
        if (self::NO_LABEL === $label) {
            return $label;
        }
        $text = str_replace(['_', '-'], ' ', $label);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * @param array<string, Issue[]> $groups
     */
    public function toMarkdown(string $title, array $groups): string
    {
        $md = '# '.$title."\n\n";
        foreach ($groups as $label => $issues) {
            $md .= '## '.self::humanizeLabel($label)."\n\n";
            foreach ($issues as $issue) {
                $md .= sprintf("- %s[%s](%s) %s\n", $issue->isBug() ? '🐞 ' : '', $issue->key, $issue->url, $issue->summary);
            }
            $md .= "\n";
        }

        return $md;
    }
}
