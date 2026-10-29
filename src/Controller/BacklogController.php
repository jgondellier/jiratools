<?php

namespace App\Controller;

use App\Entity\Project;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;
use App\Service\BacklogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/backlog')]
class BacklogController extends AbstractController
{
    #[Route('', name: 'backlog_index', methods: ['GET'])]
    public function index(ProjectRepository $projects): Response
    {
        $first = $projects->findOneBy(['active' => true], ['name' => 'ASC']);
        if (!$first) {
            $this->addFlash('warning', 'Paramétrez d\'abord un projet.');

            return $this->redirectToRoute('admin_project_new');
        }

        return $this->redirectToRoute('backlog_show', ['id' => $first->getId()]);
    }

    #[Route('/{id}', name: 'backlog_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Project $project, ProjectRepository $projects, BacklogService $backlog, JiraClient $jira): Response
    {
        $filters = [
            'component' => $request->query->get('component') ?: null,
            'label' => $request->query->get('label') ?: null,
            'type' => $request->query->get('type') ?: null,
            'text' => $request->query->get('text') ?: null,
            'includeSprint' => $request->query->getBoolean('includeSprint'),
        ];

        // Liste complète (non filtrée) pour alimenter les listes de filtres et la synthèse ;
        // les filtres sont appliqués sur cette liste, seule la recherche plein texte interroge à nouveau Jira
        $all = $backlog->getBacklog($project, ['includeSprint' => $filters['includeSprint']]);
        $issues = $filters['text'] ? $backlog->getBacklog($project, $filters) : $backlog->filter($all, $filters);
        $jql = $backlog->buildJql($project, $filters);

        return $this->render('backlog/show.html.twig', [
            'project' => $project,
            'projects' => $projects->findBy(['active' => true], ['name' => 'ASC']),
            'issues' => $issues,
            'summary' => $backlog->summarize($all),
            'filtered_summary' => $backlog->summarize($issues),
            'filters' => $filters,
            'jql' => $jira->effectiveJql($jql),
            'jira_url' => $jira->searchUrl($jql),
            'rank_unavailable' => $jira->isRankUnavailable(),
        ]);
    }
}
