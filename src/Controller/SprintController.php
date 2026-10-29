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
        $active = $projects->findBy(['active' => true], ['name' => 'ASC']);
        $sprints->prefetch($active);

        $items = [];
        foreach ($active as $p => $project) {
            foreach ($sprints->getActiveSprints($project) as $sprint) {
                $items[] = [$project, $sprint, $p];
            }
        }
        $reports = $sprints->getReports(array_map(fn ($item) => [$item[0], $item[1]], $items));

        $rows = [];
        foreach ($active as $p => $project) {
            $rows[$p] = ['project' => $project, 'reports' => [], 'next' => $sprints->getNextSprint($project)];
        }
        foreach ($items as $i => $item) {
            $rows[$item[2]]['reports'][] = $reports[$i];
        }

        return $this->render('sprint/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/{id}', name: 'sprint_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Project $project, SprintService $sprints): Response
    {
        $sprints->prefetch([$project], ['active', 'closed']);
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
            ...$sprints->getSprintOverview($project, $sprint),
        ]);
    }
}
