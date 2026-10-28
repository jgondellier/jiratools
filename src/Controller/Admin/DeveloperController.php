<?php

namespace App\Controller\Admin;

use App\Atlassian\AtlassianException;
use App\Entity\Developer;
use App\Form\DeveloperType;
use App\Jira\JiraClient;
use App\Repository\DeveloperRepository;
use App\Repository\ProjectRepository;
use App\Repository\TeamRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/parametrage/membres', name: 'admin_developer')]
class DeveloperController extends AbstractCrudController
{
    protected function routePrefix(): string
    {
        return 'admin_developer';
    }

    protected function formType(): string
    {
        return DeveloperType::class;
    }

    protected function labels(): array
    {
        return ['membre', 'membres'];
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(DeveloperRepository $repository): Response
    {
        return $this->render('admin/developer/index.html.twig', [
            'developers' => $repository->findBy([], ['displayName' => 'ASC']),
        ]);
    }

    #[Route('/nouveau', name: '_new', methods: ['GET', 'POST'])]
    public function new(Request $request, TeamRepository $teams): Response
    {
        $developer = new Developer();
        if ($teamId = $request->query->getInt('team')) {
            $developer->setTeam($teams->find($teamId));
        }

        return $this->handleForm($request, $developer);
    }

    #[Route('/{id}', name: '_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Developer $developer): Response
    {
        return $this->handleForm($request, $developer);
    }

    #[Route('/{id}/supprimer', name: '_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Developer $developer): Response
    {
        return $this->handleDelete($request, $developer);
    }

    /**
     * Import des membres à partir des utilisateurs assignables d'un projet Jira.
     */
    #[Route('/import', name: '_import', methods: ['GET', 'POST'])]
    public function import(
        Request $request,
        JiraClient $jira,
        ProjectRepository $projects,
        TeamRepository $teams,
        DeveloperRepository $developers,
    ): Response {
        $keys = array_values(array_unique(array_map(fn ($p) => $p->getJiraProjectKey(), $projects->findAll())));
        $projectKey = strtoupper((string) $request->query->get('project', $keys[0] ?? ''));
        $users = [];
        $error = null;

        if ($projectKey) {
            try {
                $users = $jira->getAssignableUsers($projectKey);
            } catch (AtlassianException $e) {
                $error = $e->getMessage();
            }
        }

        $known = [];
        foreach ($developers->findAll() as $dev) {
            $known[$dev->getJiraAccountId()] = $dev;
        }

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('import', (string) $request->request->get('_token'))) {
            $team = $teams->find($request->request->getInt('team'));
            $selected = (array) $request->request->all('users');
            $count = 0;
            foreach ($users as $user) {
                if (!\in_array($user['id'], $selected, true)) {
                    continue;
                }
                $dev = $known[$user['id']] ?? (new Developer())->setJiraAccountId($user['id']);
                $dev->setDisplayName($user['name'])
                    ->setEmail($user['email'] ?: $dev->getEmail())
                    ->setAvatarUrl($user['avatar']);
                if ($team) {
                    $dev->setTeam($team);
                }
                $this->em->persist($dev);
                ++$count;
            }
            $this->em->flush();
            $this->addFlash('success', sprintf('%d membre(s) importé(s) ou mis à jour.', $count));

            return $this->redirectToRoute('admin_developer_index');
        }

        return $this->render('admin/developer/import.html.twig', [
            'keys' => $keys,
            'project_key' => $projectKey,
            'users' => $users,
            'known' => $known,
            'teams' => $teams->findBy([], ['name' => 'ASC']),
            'error' => $error,
        ]);
    }
}
