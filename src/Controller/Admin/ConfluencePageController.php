<?php

namespace App\Controller\Admin;

use App\Entity\ConfluencePage;
use App\Form\ConfluencePageType;
use App\Repository\ConfluencePageRepository;
use App\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/parametrage/pages-confluence', name: 'admin_page')]
class ConfluencePageController extends AbstractCrudController
{
    protected function routePrefix(): string
    {
        return 'admin_page';
    }

    protected function formType(): string
    {
        return ConfluencePageType::class;
    }

    protected function labels(): array
    {
        return ['page Confluence', 'pages Confluence'];
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(ConfluencePageRepository $repository): Response
    {
        $pages = $repository->findAll();
        usort($pages, fn (ConfluencePage $a, ConfluencePage $b) => [$a->getProject()?->getName(), $a->getCategory(), $a->getTitle()] <=> [$b->getProject()?->getName(), $b->getCategory(), $b->getTitle()]);

        return $this->render('admin/page/index.html.twig', ['pages' => $pages]);
    }

    #[Route('/nouvelle', name: '_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProjectRepository $projects): Response
    {
        $page = new ConfluencePage();
        if ($projectId = (int) $request->query->get('project')) {
            $page->setProject($projects->find($projectId));
        }

        return $this->handleForm($request, $page);
    }

    #[Route('/{id}', name: '_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ConfluencePage $page): Response
    {
        return $this->handleForm($request, $page);
    }

    #[Route('/{id}/supprimer', name: '_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ConfluencePage $page): Response
    {
        return $this->handleDelete($request, $page);
    }
}
