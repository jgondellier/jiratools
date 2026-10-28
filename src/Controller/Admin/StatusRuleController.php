<?php

namespace App\Controller\Admin;

use App\Entity\StatusRule;
use App\Form\StatusRuleType;
use App\Repository\StatusRuleRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/parametrage/seuils-statuts', name: 'admin_rule')]
class StatusRuleController extends AbstractCrudController
{
    public const SUGGESTED_STATUSES = ['En cours', 'In Progress', 'Code Review', 'En revue', 'En test', 'Testing', 'A valider', 'Bloqué', 'Blocked', 'Ready for dev'];

    protected function routePrefix(): string
    {
        return 'admin_rule';
    }

    protected function formType(): string
    {
        return StatusRuleType::class;
    }

    protected function labels(): array
    {
        return ['seuil', 'seuils'];
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(StatusRuleRepository $repository): Response
    {
        return $this->render('admin/rule/index.html.twig', [
            'rules' => $repository->findBy([], ['statusName' => 'ASC']),
            'default_warning' => $this->getParameter('app.default_warning_days'),
            'default_critical' => $this->getParameter('app.default_critical_days'),
        ]);
    }

    #[Route('/nouveau', name: '_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new StatusRule(), ['datalist' => self::SUGGESTED_STATUSES]);
    }

    #[Route('/{id}', name: '_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, StatusRule $rule): Response
    {
        return $this->handleForm($request, $rule, ['datalist' => self::SUGGESTED_STATUSES]);
    }

    #[Route('/{id}/supprimer', name: '_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, StatusRule $rule): Response
    {
        return $this->handleDelete($request, $rule);
    }
}
