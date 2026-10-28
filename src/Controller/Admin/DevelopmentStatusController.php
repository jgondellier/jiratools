<?php

namespace App\Controller\Admin;

use App\Atlassian\AtlassianException;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;
use App\Service\DevelopmentStatuses;
use App\Service\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Choix des statuts Jira qui signifient "le développeur travaille sur ce ticket".
 */
class DevelopmentStatusController extends AbstractController
{
    #[Route('/parametrage/statuts-developpement', name: 'admin_dev_statuses', methods: ['GET', 'POST'])]
    public function index(Request $request, DevelopmentStatuses $developmentStatuses, JiraClient $jira, ProjectRepository $projects): Response
    {
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('dev_statuses', (string) $request->request->get('_token'))) {
            $selected = $request->request->all('statuses');
            $extra = preg_split('/[\r\n,;]+/', (string) $request->request->get('extra')) ?: [];
            $developmentStatuses->setStatuses(array_merge($selected, $extra));
            $this->addFlash('success', [] === $developmentStatuses->getStatuses()
                ? 'Aucun statut sélectionné : tous les statuts de la catégorie « En cours » seront pris en compte.'
                : 'Statuts de développement enregistrés.');

            return $this->redirectToRoute('admin_dev_statuses');
        }

        $jiraStatuses = [];
        $error = null;
        try {
            $jiraStatuses = $jira->getStatuses(ProjectScope::jiraKeys($projects->findBy(['active' => true])));
        } catch (AtlassianException $e) {
            $error = $e->getMessage();
        }

        // Statuts enregistrés qui n'existent pas (ou plus) dans les projets Jira suivis
        $configured = $developmentStatuses->getStatuses();
        $unknown = array_values(array_filter($configured, fn (string $s) => !isset($jiraStatuses[mb_strtolower($s)])));

        $byCategory = ['indeterminate' => [], 'new' => [], 'done' => []];
        foreach ($jiraStatuses as $status) {
            $byCategory[$status['category']][] = $status;
        }

        return $this->render('admin/dev_statuses.html.twig', [
            'by_category' => array_filter($byCategory),
            'selected' => array_map('mb_strtolower', $configured),
            'unknown' => $unknown,
            'error' => $error,
            'configured' => $configured,
        ]);
    }
}
