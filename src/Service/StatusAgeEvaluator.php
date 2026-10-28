<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\StatusRule;
use App\Jira\Issue;
use App\Repository\StatusRuleRepository;

/**
 * Détermine depuis combien de temps un ticket est dans son statut et le niveau d'alerte associé.
 *
 * Ordre de recherche du seuil : règle du projet > règle globale > seuil par défaut
 * (le seuil par défaut ne s'applique qu'aux statuts de la catégorie "En cours").
 */
class StatusAgeEvaluator
{
    /** @var StatusRule[]|null */
    private ?array $rules = null;

    public function __construct(
        private readonly StatusRuleRepository $repository,
        private readonly WorkingTime $workingTime,
        private readonly float $defaultWarningDays,
        private readonly float $defaultCriticalDays,
    ) {
    }

    public function evaluate(Issue $issue, ?Project $project = null): StatusAge
    {
        $days = $issue->statusSince ? $this->workingTime->daysBetween($issue->statusSince) : 0.0;
        [$warning, $critical] = $this->thresholds($issue, $project);

        if ($issue->isDone() || null === $warning) {
            return new StatusAge($days, StatusAge::OK, $warning, $critical);
        }

        $level = match (true) {
            $days >= $critical => StatusAge::CRITICAL,
            $days >= $warning => StatusAge::WARNING,
            default => StatusAge::OK,
        };

        return new StatusAge($days, $level, $warning, $critical);
    }

    /**
     * Noms des statuts faisant l'objet d'une règle explicite.
     *
     * @return string[]
     */
    public function getRuledStatuses(): array
    {
        return array_values(array_unique(array_map(fn (StatusRule $r) => $r->getStatusName(), $this->rules())));
    }

    /** @return array{0: ?float, 1: ?float} */
    private function thresholds(Issue $issue, ?Project $project): array
    {
        $status = mb_strtolower($issue->status);
        $global = null;
        $projectRule = null;
        foreach ($this->rules() as $rule) {
            if (mb_strtolower($rule->getStatusName()) !== $status) {
                continue;
            }
            $ruleProject = $rule->getProject();
            if (null === $ruleProject) {
                $global = $rule;
            } elseif (($project && $ruleProject->getId() === $project->getId())
                || (!$project && $ruleProject->getJiraProjectKey() === $issue->projectKey)) {
                $projectRule = $rule;
            }
        }

        $rule = $projectRule ?? $global;
        if ($rule) {
            return [$rule->getWarningDays(), $rule->getCriticalDays()];
        }
        if ($issue->isInProgress()) {
            return [$this->defaultWarningDays, $this->defaultCriticalDays];
        }

        return [null, null];
    }

    /** @return StatusRule[] */
    private function rules(): array
    {
        return $this->rules ??= $this->repository->findAll();
    }
}
