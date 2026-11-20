<?php

namespace App\Controller\Admin;

use App\GitLab\GitLabClient;
use App\Release\ReleaseContentService;
use App\Release\ReleaseContentSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReleaseContentSettingsController extends AbstractController
{
    #[Route('/parametrage/contenu-mep', name: 'admin_release_content', methods: ['GET', 'POST'])]
    public function index(Request $request, ReleaseContentSettings $settings, ReleaseContentService $service, GitLabClient $gitlab): Response
    {
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('release_content', (string) $request->request->get('_token'))) {
            $settings->save([
                'repositories' => ReleaseContentSettings::parseRepositories((string) $request->request->get('repositories')),
                'branch' => (string) $request->request->get('branch'),
                'reference' => (string) $request->request->get('reference'),
                'ticketProjects' => ReleaseContentSettings::parseProjectKeys((string) $request->request->get('ticketProjects')),
                'expectedStatuses' => ReleaseContentSettings::parseList((string) $request->request->get('expectedStatuses')),
                'tagCount' => (int) $request->request->get('tagCount', 10),
            ]);
            $this->addFlash('success', 'Paramétrage enregistré. Vérifiez ci-dessous la lecture des dépôts.');

            return $this->redirectToRoute('admin_release_content');
        }

        // Aperçu de la lecture de chaque dépôt, pour valider le paramétrage
        $scans = [];
        if ($gitlab->isConfigured()) {
            foreach ($service->getRepositories() as $repository) {
                $scans[] = $service->scanRepository($repository);
            }
        }

        return $this->render('admin/release_content.html.twig', [
            'settings' => $settings,
            'gitlab_configured' => $gitlab->isConfigured(),
            'gitlab_url' => $gitlab->getBaseUrl(),
            'repositories_text' => ReleaseContentSettings::formatRepositories($settings->getRepositories()),
            'default_keys' => $settings->getTicketProjects() ? null : $service->getTicketProjectKeys(),
            'scans' => $scans,
        ]);
    }
}
