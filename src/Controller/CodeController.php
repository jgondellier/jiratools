<?php

namespace App\Controller;

use App\Entity\Project;
use App\GitLab\GitLabClient;
use App\Jira\JiraClient;
use App\Release\CodeTrackingService;
use App\Release\ReleaseContentSettings;
use App\Release\ReleaseTicket;
use App\Release\TrackedTicket;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Code des projets (un dépôt GitLab par projet) : tickets dans develop ou dans un tag de version, contenu d'un tag.
 */
#[Route('/code')]
class CodeController extends AbstractController
{
    #[Route('/projet', name: 'code_project', methods: ['GET'])]
    public function project(Request $request, ProjectRepository $projects, CodeTrackingService $tracking, ReleaseContentSettings $settings, GitLabClient $gitlab): Response
    {
        [$project, $withRepository] = $this->selectProject($request, $projects);
        if ($redirect = $this->checkConfiguration($gitlab, $withRepository)) {
            return $redirect;
        }

        $report = $tracking->getProjectReport($project);
        $type = (string) $request->query->get('type');
        $onlyProblems = $request->query->getBoolean('problems');
        $location = (string) $request->query->get('where');
        $tickets = array_filter($report->tickets, fn (TrackedTicket $t) => ('' === $type || $t->issue?->type === $type)
            && (!$onlyProblems || TrackedTicket::OK !== $t->getLevel())
            && match ($location) {
                'develop' => $t->inDevelop && [] === $t->tags,
                'tag' => [] !== $t->tags,
                'none' => !$t->isInCode(),
                default => true,
            });

        return $this->render('code/project.html.twig', [
            'projects' => $withRepository,
            'project' => $project,
            'report' => $report,
            'tickets' => $tickets,
            'counts' => $report->getCounts(),
            'type' => $type,
            'where' => $location,
            'only_problems' => $onlyProblems,
            'settings' => $settings,
        ]);
    }

    #[Route('/tag', name: 'code_tag', methods: ['GET'])]
    public function tag(Request $request, ProjectRepository $projects, CodeTrackingService $tracking, ReleaseContentSettings $settings, GitLabClient $gitlab, JiraClient $jira): Response
    {
        [$project, $withRepository] = $this->selectProject($request, $projects);
        if ($redirect = $this->checkConfiguration($gitlab, $withRepository)) {
            return $redirect;
        }

        $result = $tracking->getTagReport($project, $request->query->get('tag') ?: null);
        $content = $result['content'];
        $keysToFix = $content?->getKeysToFix() ?? [];

        // Tous les commits de l'intervalle, chacun avec ses tickets (ceux de sa merge request à défaut des siens)
        $commits = [];
        foreach ($content?->repositories ?? [] as $scan) {
            foreach ($scan->mergeRequests as $mr) {
                foreach ($mr['commitList'] ?: [['shortSha' => '', 'title' => $mr['title'], 'url' => $mr['url'], 'author' => $mr['author'], 'date' => $mr['mergedAt'], 'keys' => []]] as $commit) {
                    $commits[] = $commit + ['mr' => $mr, 'ticketKeys' => $commit['keys'] ?: $mr['keys']];
                }
            }
            foreach ($scan->directCommits as $commit) {
                $commits[] = $commit + ['mr' => null, 'ticketKeys' => $commit['keys']];
            }
        }
        usort($commits, fn ($a, $b) => ($b['date'] ?? new \DateTimeImmutable('@0')) <=> ($a['date'] ?? new \DateTimeImmutable('@0')));

        return $this->render('code/tag.html.twig', [
            'projects' => $withRepository,
            'project' => $project,
            'result' => $result,
            'content' => $content,
            'commits' => $commits,
            'without_ticket' => \count(array_filter($commits, fn ($c) => [] === $c['ticketKeys'])),
            'counts' => $content?->getCounts() ?? [ReleaseTicket::ERROR => 0, ReleaseTicket::WARNING => 0, ReleaseTicket::OK => 0],
            'settings' => $settings,
            'fix_url' => [] !== $keysToFix ? $jira->searchUrl(sprintf('key in (%s) ORDER BY key', implode(', ', $keysToFix))) : null,
            'version_url' => $content?->versionJql ? $jira->searchUrl($content->versionJql) : null,
        ]);
    }

    /**
     * Projet choisi (paramètre "project"), à défaut le premier projet actif qui a un dépôt GitLab.
     *
     * @return array{0: ?Project, 1: Project[]}
     */
    private function selectProject(Request $request, ProjectRepository $projects): array
    {
        $withRepository = array_values(array_filter(
            $projects->findBy(['active' => true], ['name' => 'ASC']),
            fn (Project $p) => null !== $p->getGitlabRepository(),
        ));
        $id = (int) $request->query->get('project');
        $selected = null;
        foreach ($withRepository as $p) {
            if ($p->getId() === $id) {
                $selected = $p;
            }
        }

        return [$selected ?? $withRepository[0] ?? null, $withRepository];
    }

    private function checkConfiguration(GitLabClient $gitlab, array $withRepository): ?Response
    {
        if (!$gitlab->isConfigured()) {
            $this->addFlash('info', 'Renseignez GITLAB_BASE_URL et GITLAB_API_TOKEN dans le fichier .env.local.');

            return $this->redirectToRoute('admin_release_content');
        }
        if ([] === $withRepository) {
            $this->addFlash('info', 'Indiquez le dépôt GitLab de vos projets (champ « Dépôt GitLab » de chaque projet).');

            return $this->redirectToRoute('admin_project_index');
        }

        return null;
    }
}
