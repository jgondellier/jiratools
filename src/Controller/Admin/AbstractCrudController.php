<?php

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD générique du paramétrage (liste / création / modification / suppression).
 */
abstract class AbstractCrudController extends AbstractController
{
    public function __construct(protected readonly EntityManagerInterface $em)
    {
    }

    /** Préfixe des routes, ex. admin_team */
    abstract protected function routePrefix(): string;

    abstract protected function formType(): string;

    /** Libellés : [singulier, pluriel] */
    abstract protected function labels(): array;

    protected function handleForm(Request $request, object $entity, array $extra = []): Response
    {
        $isNew = null === $entity->getId();
        $form = $this->createForm($this->formType(), $entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($entity);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s enregistré(e).', ucfirst($this->labels()[0])));

            $redirect = $request->query->get('redirect');
            if (\is_string($redirect) && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
                return $this->redirect($redirect);
            }

            return $this->redirectToRoute($this->routePrefix().'_index');
        }

        return $this->render('admin/form.html.twig', $extra + [
            'form' => $form,
            'title' => ($isNew ? 'Nouveau / nouvelle ' : 'Modifier : ').$this->labels()[0],
            'entity' => $entity,
            'route_prefix' => $this->routePrefix(),
        ]);
    }

    protected function handleDelete(Request $request, object $entity): Response
    {
        if ($this->isCsrfTokenValid('delete'.$entity->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($entity);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s supprimé(e).', ucfirst($this->labels()[0])));
        }

        return $this->redirectToRoute($this->routePrefix().'_index');
    }
}
