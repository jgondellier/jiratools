<?php

namespace App\LocalUpdate;

use App\Entity\LocalUpdateRun;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Lance la mise à jour dans un processus PHP séparé (php bin/console app:local-update --run=ID) :
 *  - l'écran affiche le journal en direct, sans limite de durée de la requête ;
 *  - Jira Tools peut se mettre à jour lui-même : le processus utilise son propre dossier de cache
 *    (APP_CACHE_DIR), que le cache:clear de la mise à jour ne supprime pas.
 */
class BackgroundLauncher
{
    public function __construct(
        private readonly CommandRunner $runner,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory,
        #[Autowire('%kernel.project_dir%/var/local-updates')]
        private readonly string $workDirectory,
    ) {
    }

    public function launch(LocalUpdateRun $run, bool $freshCache = true): void
    {
        $cacheDir = $this->workDirectory.'/worker-cache';
        if ($freshCache) {
            $this->remove($cacheDir);
        }
        @mkdir($this->workDirectory, 0777, true);

        $process = Process::fromShellCommandline(
            '"${:LU_PHP}" "${:LU_CONSOLE}" app:local-update --run="${:LU_RUN}" --no-interaction >> "${:LU_LOG}" 2>&1',
            $this->projectDirectory,
            [
                'LU_PHP' => $this->runner->getPhpBinary(),
                'LU_CONSOLE' => $this->projectDirectory.'/bin/console',
                'LU_RUN' => (string) $run->getId(),
                'LU_LOG' => $this->workDirectory.'/worker.log',
                'APP_CACHE_DIR' => $cacheDir,
            ] + CommandRunner::systemEnvironment(),
            null,
            null,
        );
        // Le processus continue après la fin de la requête (Windows et Linux)
        $process->setOptions(['create_new_console' => true]);
        $process->disableOutput();
        $process->start();
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
