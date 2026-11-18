<?php

namespace App\Controller;

use App\Entity\LocalUpdateRun;
use App\Form\LocalAppType;
use App\LocalUpdate\ArchiveRequest;
use App\LocalUpdate\BackgroundLauncher;
use App\LocalUpdate\LocalUpdater;
use App\LocalUpdate\UpdateOptions;
use App\Repository\LocalAppRepository;
use App\Repository\LocalUpdateRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mise à jour de CETTE installation de Jira Tools : zip Git → répertoire de Jira Tools → commandes
 * (composer, mise à jour de la base, cache…). Aucune autre application ne peut être visée.
 */
#[Route('/mises-a-jour', name: 'app_local_update')]
class LocalUpdateController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocalUpdater $updater,
        private readonly LocalAppRepository $settings,
        #[Autowire('%env(default::APP_UPDATE_BACKGROUND)%')]
        ?string $background,
    ) {
        // Variable absente ou vide : processus séparé
        $this->background = null === $background || '' === $background || filter_var($background, FILTER_VALIDATE_BOOL);
    }

    private readonly bool $background;

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(LocalUpdateRunRepository $runs): Response
    {
        $settings = $this->settings->getSettings();

        $checks = [];
        try {
            $checks[] = ['ok' => true, 'label' => 'Archive', 'detail' => ArchiveRequest::for($settings)->describe()];
        } catch (\Throwable $e) {
            $checks[] = ['ok' => false, 'label' => 'Archive', 'detail' => $e->getMessage()];
        }
        try {
            $target = $this->updater->getTargetDirectory();
            $checks[] = ['ok' => is_writable($target), 'label' => 'Répertoire de Jira Tools', 'detail' => $target.(is_writable($target) ? '' : ' — non modifiable : droits insuffisants')];
        } catch (\Throwable $e) {
            $target = '';
            $checks[] = ['ok' => false, 'label' => 'Répertoire de Jira Tools', 'detail' => $e->getMessage()];
        }
        $runner = $this->updater->getRunner();
        $php = $runner->getPhpBinary();
        $checks[] = ['ok' => 'php' === $php || is_file($php), 'label' => 'PHP', 'detail' => $php.('php' === $php ? ' (cherché dans le PATH : précisez APP_UPDATE_PHP_BINARY si besoin)' : (is_file($php) ? '' : ' — introuvable : vérifiez APP_UPDATE_PHP_BINARY'))];
        $composer = $runner->describeComposer();
        $checks[] = [
            'ok' => true,
            'label' => 'Composer',
            'detail' => $runner->expand('{composer}', $target).' ('.$composer['source'].')'
                .('commande du PATH' === $composer['source'] ? ' — si vous utilisez composer.phar, placez-le à la racine de Jira Tools ou indiquez APP_UPDATE_COMPOSER="{php} C:/chemin/composer.phar"' : ''),
        ];

        return $this->render('local_update/index.html.twig', [
            'settings' => $settings,
            'running' => $this->updater->isLocked($settings),
            'checks' => $checks,
            'commands' => array_map(fn (string $c) => ['raw' => $c, 'expanded' => $runner->expand($c, $target)], $settings->getCommandList()),
            'runs' => $runs->findLatest($settings, 20),
        ]);
    }

    #[Route('/parametres', name: '_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $settings = $this->settings->getSettings();
        $form = $this->createForm(LocalAppType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Paramètres de mise à jour enregistrés.');

            return $this->redirectToRoute('app_local_update_index');
        }

        return $this->render('local_update/form.html.twig', ['form' => $form]);
    }

    #[Route('/lancer', name: '_run', methods: ['POST'])]
    public function run(Request $request, BackgroundLauncher $launcher): Response
    {
        $settings = $this->settings->getSettings();
        if (!$this->isCsrfTokenValid('local-update-run', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Formulaire expiré : relancez la mise à jour.');

            return $this->redirectToRoute('app_local_update_index');
        }
        if ($this->updater->isLocked($settings)) {
            $this->addFlash('warning', 'Une mise à jour est déjà en cours.');

            return $this->redirectToRoute('app_local_update_index');
        }

        $run = $this->updater->prepare($settings, new UpdateOptions(
            ref: trim((string) $request->request->get('ref')) ?: null,
            deploy: $request->request->getBoolean('deploy'),
            commands: array_map('intval', $request->request->all('commands')),
            dryRun: $request->request->getBoolean('dry_run'),
        ));
        // URL calculée avant la mise à jour (Jira Tools vide son propre cache pendant celle-ci)
        $url = $this->generateUrl('app_local_update_run_show', ['id' => $run->getId()]);

        if ($this->background) {
            try {
                $launcher->launch($run);

                return $this->redirect($url);
            } catch (\Throwable $e) {
                $run->setLog('Lancement en arrière-plan impossible ('.$e->getMessage().") : exécution dans la page.\n");
            }
        }

        // Exécution dans la requête (APP_UPDATE_BACKGROUND=0) : la session est libérée pour les autres onglets
        if ($request->hasSession()) {
            $request->getSession()->save();
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $this->updater->execute($run);

        return $this->redirect($url);
    }

    #[Route('/execution/{id}', name: '_run_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function runShow(LocalUpdateRun $run): Response
    {
        $this->updater->markIfInterrupted($run);

        return $this->render('local_update/run.html.twig', ['run' => $run]);
    }

    #[Route('/execution/{id}/journal', name: '_run_log', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function runLog(LocalUpdateRun $run): JsonResponse
    {
        $this->updater->markIfInterrupted($run);

        return new JsonResponse([
            'status' => $run->getStatus(),
            'finished' => $run->isFinished(),
            'log' => $run->getLog(),
            'waiting' => time() - $run->getStartedAt()->getTimestamp(),
        ]);
    }
}
