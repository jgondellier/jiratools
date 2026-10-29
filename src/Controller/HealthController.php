<?php

namespace App\Controller;

use App\Repository\ConfluencePageRepository;
use App\Repository\ProjectRepository;
use App\Service\HealthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    /**
     * Suivi & alertes : tickets qui traînent, hygiène Jira, pages Confluence obsolètes.
     */
    #[Route('/suivi', name: 'health', methods: ['GET'])]
    public function index(Request $request, ProjectRepository $projectRepository, ConfluencePageRepository $pageRepository, HealthService $health): Response
    {
        $allProjects = $projectRepository->findBy(['active' => true], ['name' => 'ASC']);
        $projectId = (int) $request->query->get('project');
        $projects = $projectId ? array_values(array_filter($allProjects, fn ($p) => $p->getId() === $projectId)) : $allProjects;

        $pages = array_filter(
            $pageRepository->findAll(),
            fn ($p) => null !== $p->getMaxAgeDays() && \in_array($p->getProject(), $projects, true),
        );
        $overview = $health->getOverview($projects);
        $pagesStatus = array_values(array_filter($health->getPagesStatus($pages), fn ($s) => $s['stale'] || $s['error']));

        return $this->render('health/index.html.twig', [
            'projects' => $allProjects,
            'project_id' => $projectId,
            'stuck' => $overview['stuck'],
            'checks' => $overview['checks'],
            'pages' => $pagesStatus,
        ]);
    }
}
