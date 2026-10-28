<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Form\ProjectType;
use App\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/parametrage/projets', name: 'admin_project')]
class ProjectController extends AbstractCrudController
{
    protected function routePrefix(): string
    {
        return 'admin_project';
    }

    protected function formType(): string
    {
        return ProjectType::class;
    }

    protected function labels(): array
    {
        return ['projet', 'projets'];
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(ProjectRepository $repository): Response
    {
        return $this->render('admin/project/index.html.twig', ['projects' => $repository->findBy([], ['name' => 'ASC'])]);
    }

    #[Route('/nouveau', name: '_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new Project());
    }

    #[Route('/{id}', name: '_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Project $project): Response
    {
        return $this->handleForm($request, $project);
    }

    #[Route('/{id}/supprimer', name: '_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Project $project): Response
    {
        return $this->handleDelete($request, $project);
    }
}
