<?php

namespace App\Controller\Admin;

use App\Entity\Team;
use App\Form\TeamType;
use App\Repository\TeamRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/parametrage/equipes', name: 'admin_team')]
class TeamController extends AbstractCrudController
{
    protected function routePrefix(): string
    {
        return 'admin_team';
    }

    protected function formType(): string
    {
        return TeamType::class;
    }

    protected function labels(): array
    {
        return ['équipe', 'équipes'];
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(TeamRepository $repository): Response
    {
        return $this->render('admin/team/index.html.twig', ['teams' => $repository->findBy([], ['name' => 'ASC'])]);
    }

    #[Route('/nouvelle', name: '_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new Team());
    }

    #[Route('/{id}', name: '_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Team $team): Response
    {
        return $this->handleForm($request, $team);
    }

    #[Route('/{id}/supprimer', name: '_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Team $team): Response
    {
        return $this->handleDelete($request, $team);
    }
}
