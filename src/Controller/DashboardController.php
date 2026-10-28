<?php

namespace App\Controller;

use App\Repository\TeamRepository;
use App\Service\ActivityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    /**
     * "Qui fait quoi" : ticket en cours de chaque membre, à l'instant.
     */
    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request, TeamRepository $teamRepository, ActivityService $activity): Response
    {
        $allTeams = $teamRepository->findBy([], ['name' => 'ASC']);
        if ([] === $allTeams) {
            return $this->render('dashboard/welcome.html.twig');
        }

        $teamId = (int) $request->query->get('team');
        $teams = $teamId ? array_values(array_filter($allTeams, fn ($t) => $t->getId() === $teamId)) : $allTeams;

        $board = $activity->getTeamsActivity($teams);
        $stats = ['members' => 0, 'idle' => 0, 'overWip' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($board as $row) {
            foreach ($row['members'] as $member) {
                ++$stats['members'];
                $stats['idle'] += (int) $member->isIdleAlert();
                $stats['overWip'] += (int) $member->isOverWip();
                foreach ($member->inProgress as $view) {
                    if (isset($stats[$view->age->level])) {
                        ++$stats[$view->age->level];
                    }
                }
            }
        }

        return $this->render($request->query->getBoolean('partial') ? 'dashboard/_board.html.twig' : 'dashboard/index.html.twig', [
            'all_teams' => $allTeams,
            'team_id' => $teamId,
            'board' => $board,
            'stats' => $stats,
            'unassigned' => $activity->getUnassignedInProgress(),
            'generated_at' => new \DateTimeImmutable(),
        ]);
    }
}
