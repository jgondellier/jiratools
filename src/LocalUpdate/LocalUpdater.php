<?php

namespace App\LocalUpdate;

use App\Entity\LocalApp;
use App\Entity\LocalUpdateRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mise à jour d'une application locale :
 *  1. téléchargement de l'archive zip (branche / tag / commit) ;
 *  2. décompression et copie dans le répertoire du projet (fichiers protégés conservés) ;
 *  3. commandes : composer install, migrations, cache:clear, import catalogue…
 */
class LocalUpdater
{
    public function __construct(
        private readonly ArchiveDownloader $downloader,
        private readonly ZipExtractor $extractor,
        private readonly ProjectSynchronizer $synchronizer,
        private readonly CommandRunner $runner,
        private readonly EntityManagerInterface $em,
        private readonly string $workDirectory,
        private readonly string $projectDirectory,
        private readonly int $backupsKept = 5,
    ) {
    }

    public function getRunner(): CommandRunner
    {
        return $this->runner;
    }

    /**
     * @param callable(string): void|null $output reçoit le journal au fil de l'eau
     */
    public function run(LocalApp $app, UpdateOptions $options = new UpdateOptions(), ?callable $output = null): LocalUpdateRun
    {
        $ref = trim((string) $options->ref) ?: $app->getRef();
        $run = new LocalUpdateRun($app, $ref, $options->trigger);
        $log = '';
        $write = function (string $text) use (&$log, $output): void {
            $log .= $text;
            if (null !== $output) {
                $output($text);
            }
        };
        $line = static function (string $text) use ($write): void {
            $write($text."\n");
        };
        $save = function () use ($run, &$log): void {
            $run->setLog($log);
            $this->em->flush();
        };

        $this->em->persist($run);
        $save();

        $lock = null;
        $status = $options->dryRun ? LocalUpdateRun::STATUS_SIMULATED : LocalUpdateRun::STATUS_SUCCESS;
        $summary = ['commands' => []];

        try {
            $lock = $this->lock($app);
            $target = $this->checkTarget($app->getTargetDirectory(), $options->deploy && !$options->dryRun);
            $line(sprintf('=== %s — %s%s', $app->getName(), $run->getStartedAt()->format('d/m/Y H:i:s'), $options->dryRun ? ' — SIMULATION (aucune modification)' : ''));
            $line('Projet : '.$target);

            if ($options->deploy) {
                $summary['files'] = $this->deploy($app, $ref, $target, $options->dryRun, $run, $line);
                $save();
            } else {
                $line("\n# Code : non téléchargé (commandes seules)");
            }

            $commands = $app->getCommandList();
            $selected = null === $options->commands ? array_keys($commands) : array_values(array_intersect(array_keys($commands), $options->commands));
            if ([] === $selected) {
                $line("\n# Commandes : aucune");
            }
            foreach ($selected as $index) {
                $command = $commands[$index];
                $expanded = $this->runner->expand($command, $target);
                $line(sprintf("\n# [%d/%d] %s", array_search($index, $selected, true) + 1, \count($selected), $command));
                if ($expanded !== $command) {
                    $line('  → '.$expanded);
                }
                if ($options->dryRun) {
                    $summary['commands'][] = ['command' => $command, 'exitCode' => null];
                    continue;
                }

                $started = microtime(true);
                $exitCode = $this->runner->run($command, $target, $app->getEnvironmentVariables(), function (string $buffer) use ($write): void {
                    $write($buffer);
                });
                if ('' !== $log && !str_ends_with($log, "\n")) {
                    $write("\n");
                }
                $summary['commands'][] = ['command' => $command, 'exitCode' => $exitCode, 'seconds' => round(microtime(true) - $started, 1)];
                $line(sprintf('  %s code %d (%.1f s)', 0 === $exitCode ? '✔' : '✘', $exitCode, microtime(true) - $started));
                $save();

                if (0 !== $exitCode) {
                    $status = LocalUpdateRun::STATUS_FAILED;
                    if ($app->isStopOnError()) {
                        $line('Arrêt : la commande a échoué (option « Arrêter à la première erreur »).');
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $status = LocalUpdateRun::STATUS_FAILED;
            $line("\nERREUR : ".$e->getMessage());
        } finally {
            if (null !== $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $line(sprintf("\n=== %s", match ($status) {
            LocalUpdateRun::STATUS_SUCCESS => 'Mise à jour terminée',
            LocalUpdateRun::STATUS_SIMULATED => 'Simulation terminée',
            default => 'Mise à jour en échec',
        }));
        $run->setSummary($summary)->finish($status);
        if (!$options->dryRun) {
            $app->recordRun($run);
        }
        $save();

        return $run;
    }

    /**
     * @param callable(string): void $line
     */
    private function deploy(LocalApp $app, string $ref, string $target, bool $dryRun, LocalUpdateRun $run, callable $line): array
    {
        $request = ArchiveRequest::for($app, $ref);
        $tmp = $this->workDirectory.'/tmp/'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
        if (!@mkdir($tmp, 0777, true) && !is_dir($tmp)) {
            throw new LocalUpdateException(sprintf('Impossible de créer le dossier de travail %s', $tmp));
        }

        try {
            $line(sprintf("\n# Téléchargement de « %s » : %s", $ref, $request->describe()));
            $size = $this->downloader->download($request, $tmp.'/archive.zip');
            $line(sprintf('  %s Ko reçus', number_format($size / 1024, 0, ',', ' ')));

            $line('# Décompression');
            $extracted = $this->extractor->extract($tmp.'/archive.zip', $tmp.'/src', $app->getSubdirectory());
            $run->setVersion($extracted['version'] ?? $ref);
            if (null !== $extracted['version']) {
                $line('  Version : '.$extracted['version']);
            }

            $backup = $app->isBackup() && !$dryRun ? sprintf('%s/backups/app-%d/%s', $this->workDirectory, $app->getId(), date('Ymd-His')) : null;
            $line(sprintf('# Copie dans le projet%s', $dryRun ? ' (simulation)' : ''));
            if (null === $app->getDeployedFiles() && $app->isDeleteObsolete()) {
                $line('  Premier déploiement : aucun fichier supprimé (la liste des fichiers déployés sera mémorisée).');
            }
            $result = $this->synchronizer->sync(
                $extracted['root'],
                $target,
                new PathMatcher($app->getPreservedPathList()),
                $app->getDeployedFiles(),
                $app->isDeleteObsolete(),
                $backup,
                $dryRun,
                $line,
            );
            $line(sprintf('  %d ajouté(s), %d modifié(s), %d supprimé(s), %d inchangé(s), %d protégé(s) non copié(s)', \count($result->added), \count($result->modified), \count($result->deleted), $result->unchanged, \count($result->preserved)));
            if (null !== $result->backupDirectory) {
                $line('  Sauvegarde des fichiers remplacés : '.$result->backupDirectory);
                $this->pruneBackups(\dirname($result->backupDirectory));
            }
            if (!$dryRun) {
                $app->setDeployedFiles($result->deployedFiles);
            }

            return $result->toSummary();
        } finally {
            $this->remove($tmp);
        }
    }

    /**
     * Refuse les répertoires dangereux : racine d'un disque, Jira Tools lui-même ou l'un de ses parents.
     */
    public function checkTarget(string $directory, bool $mayCreate): string
    {
        $path = rtrim(str_replace('\\', '/', trim($directory)), '/');
        if ('' === $path || !preg_match('#^(/|[A-Za-z]:/)#', $path.'/')) {
            throw new LocalUpdateException('Le répertoire du projet doit être un chemin absolu (ex : C:/EasyPHP/eds-www/mon-appli).');
        }
        if (preg_match('#^([A-Za-z]:)?$#', $path) || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new LocalUpdateException(sprintf('Répertoire refusé : %s', $directory));
        }
        $real = is_dir($path) ? str_replace('\\', '/', (string) realpath($path)) : $path;
        $self = str_replace('\\', '/', (string) realpath($this->projectDirectory));
        if (0 === strcasecmp($real, $self) || str_starts_with(strtolower($self.'/'), strtolower(rtrim($real, '/').'/'))) {
            throw new LocalUpdateException('Le répertoire du projet ne peut pas être celui de Jira Tools (ni un dossier parent).');
        }
        if (!is_dir($path) && !$mayCreate) {
            throw new LocalUpdateException(sprintf('Le répertoire du projet n\'existe pas : %s', $directory));
        }

        return $path;
    }

    /** @return resource */
    private function lock(LocalApp $app)
    {
        $dir = $this->workDirectory.'/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $handle = @fopen(sprintf('%s/app-%d.lock', $dir, $app->getId()), 'c');
        if (false === $handle) {
            throw new LocalUpdateException(sprintf('Impossible de créer le verrou dans %s', $dir));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new LocalUpdateException('Une mise à jour de cette application est déjà en cours.');
        }

        return $handle;
    }

    private function pruneBackups(string $directory): void
    {
        $backups = glob($directory.'/*', GLOB_ONLYDIR) ?: [];
        rsort($backups);
        foreach (\array_slice($backups, max(1, $this->backupsKept)) as $old) {
            $this->remove($old);
        }
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir() && !$file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0666);
                @unlink($file->getPathname());
            }
        }
        @rmdir($path);
    }
}
