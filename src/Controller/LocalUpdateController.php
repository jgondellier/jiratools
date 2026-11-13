<?php

namespace App\Controller;

use App\Controller\Admin\AbstractCrudController;
use App\Entity\LocalApp;
use App\Entity\LocalUpdateRun;
use App\Form\LocalAppType;
use App\LocalUpdate\ArchiveRequest;
use App\LocalUpdate\LocalUpdater;
use App\LocalUpdate\UpdateOptions;
use App\Repository\LocalAppRepository;
use App\Repository\LocalUpdateRunRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mises à jour des applications installées sur le poste : zip Git → projet → commandes.
 */
#[Route('/mises-a-jour', name: 'local_update')]
class LocalUpdateController extends AbstractCrudController
{
    protected function routePrefix(): string
    {
        return 'local_update';
    }

    protected function formType(): string
    {
        return LocalAppType::class;
    }

    protected function labels(): array
    {
        return ['application', 'applications'];
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(LocalAppRepository $apps, LocalUpdateRunRepository $runs): Response
    {
        return $this->render('local_update/index.html.twig', [
            'apps' => $apps->findBy([], ['name' => 'ASC']),
            'runs' => $runs->findLatest(null, 15),
        ]);
    }

    #[Route('/nouvelle', name: '_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new LocalApp());
    }

    #[Route('/{id}/modifier', name: '_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, LocalApp $app): Response
    {
        return $this->handleForm($request, $app);
    }

    #[Route('/{id}/supprimer', name: '_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, LocalApp $app): Response
    {
        return $this->handleDelete($request, $app);
    }

    #[Route('/{id}', name: '_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(LocalApp $app, LocalUpdater $updater, LocalUpdateRunRepository $runs): Response
    {
        $checks = [];
        try {
            $checks[] = ['ok' => true, 'label' => 'Archive', 'detail' => ArchiveRequest::for($app)->describe()];
        } catch (\Throwable $e) {
            $checks[] = ['ok' => false, 'label' => 'Archive', 'detail' => $e->getMessage()];
        }
        try {
            $target = $updater->checkTarget($app->getTargetDirectory(), true);
            $exists = is_dir($target);
            $checks[] = ['ok' => !$exists || is_writable($target), 'label' => 'Projet', 'detail' => $target.(!$exists ? ' (sera créé au premier déploiement)' : (is_writable($target) ? '' : ' — non modifiable : droits insuffisants'))];
        } catch (\Throwable $e) {
            $checks[] = ['ok' => false, 'label' => 'Projet', 'detail' => $e->getMessage()];
        }
        $runner = $updater->getRunner();
        $php = $runner->getPhpBinary();
        $checks[] = ['ok' => 'php' === $php || is_file($php), 'label' => 'PHP', 'detail' => $php.('php' === $php ? ' (cherché dans le PATH : précisez LOCAL_UPDATE_PHP_BINARY si besoin)' : (is_file($php) ? '' : ' — introuvable : vérifiez LOCAL_UPDATE_PHP_BINARY'))];
        $checks[] = ['ok' => true, 'label' => 'Composer', 'detail' => $runner->getComposer()];

        $target = rtrim(str_replace('\\', '/', $app->getTargetDirectory()), '/');

        return $this->render('local_update/show.html.twig', [
            'local_app' => $app,
            'checks' => $checks,
            'commands' => array_map(fn (string $c) => ['raw' => $c, 'expanded' => $runner->expand($c, $target)], $app->getCommandList()),
            'runs' => $runs->findLatest($app, 20),
        ]);
    }

    #[Route('/{id}/lancer', name: '_run', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function run(Request $request, LocalApp $app, LocalUpdater $updater): Response
    {
        if (!$this->isCsrfTokenValid('run'.$app->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Formulaire expiré : relancez la mise à jour.');

            return $this->redirectToRoute('local_update_show', ['id' => $app->getId()]);
        }

        $options = new UpdateOptions(
            ref: trim((string) $request->request->get('ref')) ?: null,
            deploy: $request->request->getBoolean('deploy'),
            commands: array_map('intval', $request->request->all('commands')),
            dryRun: $request->request->getBoolean('dry_run'),
        );

        // Mise à jour longue : la session est libérée pour ne pas bloquer les autres onglets
        if ($request->hasSession()) {
            $request->getSession()->save();
        }
        @set_time_limit(0);
        ignore_user_abort(true);

        $run = $updater->run($app, $options);

        return $this->redirectToRoute('local_update_run_show', ['id' => $run->getId()]);
    }

    #[Route('/execution/{id}', name: '_run_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function runShow(LocalUpdateRun $run): Response
    {
        return $this->render('local_update/run.html.twig', ['run' => $run]);
    }
}
