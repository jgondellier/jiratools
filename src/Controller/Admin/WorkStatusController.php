<?php

namespace App\Controller\Admin;

use App\Atlassian\AtlassianException;
use App\Entity\Developer;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;
use App\Service\ProjectScope;
use App\Service\WorkStatuses;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Choix, par rôle, des statuts Jira qui signifient "le membre travaille sur ce ticket".
 */
class WorkStatusController extends AbstractController
{
    #[Route('/parametrage/statuts-de-travail', name: 'admin_work_statuses', methods: ['GET', 'POST'])]
    public function index(Request $request, WorkStatuses $workStatuses, JiraClient $jira, ProjectRepository $projects): Response
    {
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('work_statuses', (string) $request->request->get('_token'))) {
            $config = $request->request->all('work');
            $extra = preg_split('/[\r\n,;]+/', (string) $request->request->get('extra')) ?: [];
            $config[WorkStatuses::DEFAULT] = array_merge((array) ($config[WorkStatuses::DEFAULT] ?? []), $extra);
            $workStatuses->setConfig($config);
            $this->addFlash('success', 'Statuts de travail enregistrés.');

            return $this->redirectToRoute('admin_work_statuses');
        }

        $jiraStatuses = [];
        $error = null;
        try {
            $jiraStatuses = $jira->getStatuses(ProjectScope::jiraKeys($projects->findBy(['active' => true])));
        } catch (AtlassianException $e) {
            $error = $e->getMessage();
        }

        // Statuts paramétrés absents des projets Jira suivis (renommés ?) : affichés quand même
        $unknown = [];
        foreach ($workStatuses->getAllStatuses() as $status) {
            if (!isset($jiraStatuses[mb_strtolower($status)])) {
                $unknown[mb_strtolower($status)] = ['name' => $status, 'category' => 'unknown', 'categoryName' => ''];
            }
        }

        $byCategory = [Issue::CATEGORY_IN_PROGRESS => [], Issue::CATEGORY_TODO => [], Issue::CATEGORY_DONE => [], 'unknown' => array_values($unknown)];
        foreach ($jiraStatuses as $status) {
            $byCategory[$status['category']][] = $status;
        }

        $selected = [];
        foreach ($workStatuses->getConfig() as $key => $statuses) {
            $selected[$key] = array_map('mb_strtolower', $statuses);
        }

        return $this->render('admin/work_statuses.html.twig', [
            'by_category' => array_filter($byCategory),
            'columns' => [WorkStatuses::DEFAULT => 'Par défaut'] + array_flip(Developer::ROLES),
            'selected' => $selected,
            'error' => $error,
        ]);
    }
}
