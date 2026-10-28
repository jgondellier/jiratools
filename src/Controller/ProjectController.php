<?php

namespace App\Controller;

use App\Entity\Project;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;
use App\Service\HealthService;
use App\Service\ProjectScope;
use App\Service\SprintService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/projets')]
class ProjectController extends AbstractController
{
    #[Route('', name: 'project_index', methods: ['GET'])]
    public function index(ProjectRepository $projects): Response
    {
        return $this->render('project/index.html.twig', [
            'projects' => $projects->findBy([], ['active' => 'DESC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/{id}', name: 'project_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Project $project, SprintService $sprints, HealthService $health, JiraClient $jira): Response
    {
        $reports = [];
        foreach ($sprints->getActiveSprints($project) as $sprint) {
            $reports[] = $sprints->getReport($project, $sprint);
        }
        $scope = ProjectScope::jql($project);

        return $this->render('project/show.html.twig', [
            'project' => $project,
            'reports' => $reports,
            'next_sprint' => $sprints->getNextSprint($project),
            'pages' => $health->getPagesStatus($project->getConfluencePages()->toArray()),
            'stuck' => $health->getStuckIssues([$project]),
            'jira_url' => $jira->searchUrl($scope.' ORDER BY updated DESC'),
            'board_url' => $project->getJiraBoardId() ? $jira->boardUrl($project->getJiraBoardId(), $project->getJiraProjectKey()) : null,
            'scope' => $scope,
        ]);
    }
}
