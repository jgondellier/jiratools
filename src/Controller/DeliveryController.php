<?php

namespace App\Controller;

use App\Delivery\DeliveryCheck;
use App\Delivery\DeliveryService;
use App\Delivery\DeliverySettings;
use App\Delivery\ReleaseReadiness;
use App\Delivery\ReleaseReadinessService;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Livraisons : corrélation entre mes versions et les livraisons des web services.
 */
class DeliveryController extends AbstractController
{
    #[Route('/livraisons', name: 'delivery', methods: ['GET'])]
    public function index(
        Request $request,
        DeliverySettings $settings,
        DeliveryService $deliveries,
        ReleaseReadinessService $readiness,
        ProjectRepository $projectRepository,
        JiraClient $jira,
    ): Response
    {
        if (!$settings->isConfigured()) {
            $this->addFlash('info', 'Indiquez d\'abord les projets Jira des web services et la page Confluence du calendrier des livraisons.');

            return $this->redirectToRoute('admin_deliveries');
        }

        $allProjects = $projectRepository->findBy(['active' => true], ['name' => 'ASC']);
        $projectId = (int) $request->query->get('project');
        $projects = $projectId ? array_values(array_filter($allProjects, fn ($p) => $p->getId() === $projectId)) : $allProjects;
        $onlyProblems = $request->query->getBoolean('problems');

        $calendar = $deliveries->getCalendar();
        $releases = $readiness->getUpcoming($projects, $calendar);
        $report = $deliveries->getReport($projects, $calendar);

        $counts = [DeliveryCheck::ERROR => 0, DeliveryCheck::WARNING => 0, DeliveryCheck::OK => 0];
        foreach ($report['checks'] as $check) {
            ++$counts[$check->getLevel()];
        }
        $checks = $onlyProblems ? array_filter($report['checks'], fn (DeliveryCheck $c) => DeliveryCheck::OK !== $c->getLevel()) : $report['checks'];

        // Regroupement par "ma version"
        $byVersion = [];
        foreach ($checks as $check) {
            $byVersion[$check->myVersion ?? 'Sans version'][] = $check;
        }

        return $this->render('delivery/index.html.twig', [
            'projects' => $allProjects,
            'project_id' => $projectId,
            'only_problems' => $onlyProblems,
            'report' => $report,
            'by_version' => $byVersion,
            'counts' => $counts,
            'settings' => $settings,
            'jira_browse' => rtrim($jira->browseUrl('X'), 'X'),
            'releases' => $onlyProblems ? array_values(array_filter($releases, fn (ReleaseReadiness $r) => ReleaseReadiness::OK !== $r->getLevel())) : $releases,
            'release_stats' => [
                'count' => \count($releases),
                'atRisk' => \count(array_filter($releases, fn (ReleaseReadiness $r) => ReleaseReadiness::ERROR === $r->getLevel())),
                'issuesInError' => array_sum(array_map(fn (ReleaseReadiness $r) => $r->countIssuesInAlert(ReleaseReadiness::ERROR), $releases)),
            ],
            'version_problems' => \count(array_filter($report['versions'], fn ($v) => \in_array($v['level'], ['warning', 'danger'], true))),
        ]);
    }
}
