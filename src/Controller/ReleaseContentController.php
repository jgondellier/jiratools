<?php

namespace App\Controller;

use App\GitLab\GitLabClient;
use App\Jira\JiraClient;
use App\Release\ReleaseContentService;
use App\Release\ReleaseContentSettings;
use App\Release\ReleaseTicket;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contenu de la MEP : tickets présents dans la branche livrée (develop) d'après GitLab, et état dans Jira.
 */
class ReleaseContentController extends AbstractController
{
    #[Route('/livraisons/contenu', name: 'release_content', methods: ['GET'])]
    public function index(Request $request, ReleaseContentSettings $settings, ReleaseContentService $service, GitLabClient $gitlab, JiraClient $jira): Response
    {
        if (!$gitlab->isConfigured() || [] === $service->getRepositories()) {
            $this->addFlash('info', $gitlab->isConfigured()
                ? 'Indiquez d\'abord les dépôts GitLab à lire (ici, ou le dépôt de chaque projet).'
                : 'Renseignez GITLAB_BASE_URL et GITLAB_API_TOKEN dans le fichier .env.local, puis les dépôts GitLab à lire.');

            return $this->redirectToRoute('admin_release_content');
        }

        // "v" présent dans l'URL = versions choisies (éventuellement aucune) ; absent = prochaine livraison
        $versionIds = $request->query->has('v')
            ? array_values(array_filter(array_map('strval', $request->query->all('versions'))))
            : null;
        $onlyProblems = $request->query->getBoolean('problems');

        $content = $service->getReport($versionIds);
        $tickets = $onlyProblems
            ? array_filter($content->tickets, fn (ReleaseTicket $t) => ReleaseTicket::OK !== $t->getLevel())
            : $content->tickets;
        $keysToFix = $content->getKeysToFix();

        return $this->render('release/index.html.twig', [
            'content' => $content,
            'tickets' => $tickets,
            'counts' => $content->getCounts(),
            'only_problems' => $onlyProblems,
            'settings' => $settings,
            'fix_url' => [] !== $keysToFix ? $jira->searchUrl(sprintf('key in (%s) ORDER BY key', implode(', ', $keysToFix))) : null,
            'all_url' => [] !== $content->tickets
                ? $jira->searchUrl(sprintf('key in (%s) ORDER BY key', implode(', ', array_unique(array_filter(array_map(fn (ReleaseTicket $t) => $t->issue?->key, $content->tickets))))))
                : null,
            'version_url' => $content->versionJql ? $jira->searchUrl($content->versionJql) : null,
        ]);
    }
}
