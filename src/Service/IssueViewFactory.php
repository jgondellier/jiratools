<?php

namespace App\Service;

use App\Entity\Project;
use App\Jira\Issue;
use App\Repository\ProjectRepository;

class IssueViewFactory
{
    /** @var Project[]|null */
    private ?array $projects = null;

    public function __construct(
        private readonly StatusAgeEvaluator $evaluator,
        private readonly ProjectRepository $projectRepository,
    ) {
    }

    public function create(Issue $issue, ?Project $project = null): IssueView
    {
        $project ??= ProjectScope::findProject($issue, $this->projects());

        return new IssueView($issue, $this->evaluator->evaluate($issue, $project), $project);
    }

    /**
     * @param Issue[] $issues
     *
     * @return IssueView[]
     */
    public function createAll(array $issues, ?Project $project = null): array
    {
        return array_map(fn (Issue $i) => $this->create($i, $project), $issues);
    }

    /** @return Project[] */
    private function projects(): array
    {
        return $this->projects ??= $this->projectRepository->findBy(['active' => true]);
    }
}
