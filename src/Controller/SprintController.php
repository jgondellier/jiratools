<?php

namespace App\Controller;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\SprintService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sprints')]
class SprintController extends AbstractController
{
    /**
     * Calendrier des sprints : début, fin, avancement de chaque projet.
     */
    #[Route('', name: 'sprint_index', methods: ['GET'])]
    public function index(ProjectRepository $projects, SprintService $sprints): Response
    {
        $rows = [];
        foreach ($projects->findBy(['active' => true], ['name' => 'ASC']) as $project) {
            $reports = [];
            foreach ($sprints->getActiveSprints($project) as $sprint) {
                $reports[] = $sprints->getReport($project, $sprint);
            }
            $rows[] = [
                'project' => $project,
                'reports' => $reports,
                'next' => $sprints->getNextSprint($project),
            ];
        }

        return $this->render('sprint/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/{id}', name: 'sprint_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Project $project, SprintService $sprints): Response
    {
        $available = $sprints->getSelectableSprints($project);
        $sprintId = (int) $request->query->get('sprint');
        $sprint = null;
        foreach ($available as $candidate) {
            if ($sprintId ? $candidate->id === $sprintId : $candidate->isActive()) {
                $sprint = $candidate;
                break;
            }
        }

        return $this->render('sprint/show.html.twig', [
            'project' => $project,
            'sprints' => $available,
            'report' => $sprint ? $sprints->getReport($project, $sprint) : null,
            'velocity' => $sprints->getVelocity($project),
        ]);
    }
}
