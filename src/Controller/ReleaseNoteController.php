<?php

namespace App\Controller;

use App\Atlassian\AtlassianException;
use App\Confluence\ConfluenceClient;
use App\Entity\ConfluencePage;
use App\Entity\Project;
use App\Jira\JiraClient;
use App\Repository\ProjectRepository;
use App\Service\ReleaseNoteService;
use App\Service\SprintService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/release-note')]
class ReleaseNoteController extends AbstractController
{
    #[Route('', name: 'release_note', methods: ['GET'])]
    public function index(
        Request $request,
        ProjectRepository $projects,
        JiraClient $jira,
        SprintService $sprints,
        ReleaseNoteService $releaseNotes,
    ): Response {
        $allProjects = $projects->findBy(['active' => true], ['name' => 'ASC']);
        $project = $projects->find($request->query->getInt('project')) ?? ($allProjects[0] ?? null);
        if (!$project) {
            $this->addFlash('warning', 'Paramétrez d\'abord un projet.');

            return $this->redirectToRoute('admin_project_new');
        }

        $versions = array_values(array_filter($jira->getVersions($project->getJiraProjectKey()), fn ($v) => !$v['archived']));
        usort($versions, fn ($a, $b) => [(int) $a['released'], $b['releaseDate'] ?? '9999'] <=> [(int) $b['released'], $a['releaseDate'] ?? '9999']);
        $sprintList = $sprints->getSelectableSprints($project);

        $version = $request->query->get('version') ?: null;
        $sprintId = $request->query->getInt('sprint') ?: null;
        $onlyDone = !$request->query->has('version') && !$request->query->has('sprint') ? true : $request->query->getBoolean('onlyDone');
        $result = null;
        $title = null;

        if ($version || $sprintId) {
            $result = $releaseNotes->generate($project, $version, $sprintId, $onlyDone);
            $title = $this->buildTitle($project, $version, $sprintId, $sprintList);
        }

        return $this->render('release_note/index.html.twig', [
            'projects' => $allProjects,
            'project' => $project,
            'versions' => $versions,
            'sprints' => $sprintList,
            'version' => $version,
            'sprint_id' => $sprintId,
            'only_done' => $onlyDone,
            'result' => $result,
            'title' => $title,
            'markdown' => $result ? $releaseNotes->toMarkdown($title, $result['groups']) : null,
        ]);
    }

    #[Route('/publier', name: 'release_note_publish', methods: ['POST'])]
    public function publish(
        Request $request,
        ProjectRepository $projects,
        ReleaseNoteService $releaseNotes,
        SprintService $sprints,
        ConfluenceClient $confluence,
        EntityManagerInterface $em,
    ): Response {
        $project = $projects->find($request->request->getInt('project'));
        if (!$project || !$this->isCsrfTokenValid('publish', (string) $request->request->get('_token'))) {
            throw $this->createNotFoundException();
        }
        $version = $request->request->get('version') ?: null;
        $sprintId = $request->request->getInt('sprint') ?: null;
        $onlyDone = $request->request->getBoolean('onlyDone');
        $back = $this->redirectToRoute('release_note', array_filter([
            'project' => $project->getId(), 'version' => $version, 'sprint' => $sprintId, 'onlyDone' => (int) $onlyDone,
        ]));

        $space = $request->request->get('space') ?: $project->getConfluenceSpaceKey();
        if (!$space) {
            $this->addFlash('danger', 'Renseignez l\'espace Confluence du projet (Paramétrage > Projets).');

            return $back;
        }

        $result = $releaseNotes->generate($project, $version, $sprintId, $onlyDone);
        $title = (string) ($request->request->get('title') ?: $this->buildTitle($project, $version, $sprintId, $sprints->getSelectableSprints($project)));
        $body = $this->renderView('release_note/_confluence.html.twig', ['groups' => $result['groups'], 'project' => $project, 'jql' => $result['jql']]);

        try {
            $page = $confluence->publishPage($space, $project->getReleaseNoteParentPageId(), $title, $body);
        } catch (AtlassianException $e) {
            $this->addFlash('danger', 'Publication impossible : '.$e->getMessage());

            return $back;
        }

        // Rattache la page publiée au projet
        $known = $project->getConfluencePages()->filter(fn (ConfluencePage $p) => $p->getPageId() === $page->id);
        if ($known->isEmpty()) {
            $project->addConfluencePage((new ConfluencePage())
                ->setTitle($page->title)
                ->setPageId($page->id)
                ->setUrl($page->url)
                ->setCategory('release'));
            $em->flush();
        }

        $this->addFlash('published', sprintf('Release note publiée sur Confluence : <a href="%s" target="_blank" rel="noopener">%s</a> (version %d).', htmlspecialchars($page->url), htmlspecialchars($page->title), $page->version));

        return $back;
    }

    private function buildTitle(Project $project, ?string $version, ?int $sprintId, array $sprintList): string
    {
        if ($version) {
            return sprintf('Release note %s - %s', $project->getName(), $version);
        }
        foreach ($sprintList as $sprint) {
            if ($sprint->id === $sprintId) {
                return sprintf('Release note %s - %s', $project->getName(), $sprint->name);
            }
        }

        return sprintf('Release note %s', $project->getName());
    }
}
