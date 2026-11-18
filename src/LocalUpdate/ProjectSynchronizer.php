<?php

namespace App\LocalUpdate;

/**
 * Copie les fichiers décompressés dans le répertoire du projet :
 *  - les chemins protégés (.env.local, var/, vendor/…) ne sont ni écrasés ni supprimés ;
 *  - seuls les fichiers dont le contenu change sont réécrits ;
 *  - les fichiers déployés la fois précédente et absents de la nouvelle archive sont supprimés
 *    (jamais un fichier que l'outil n'a pas lui-même déployé) ;
 *  - les fichiers remplacés ou supprimés peuvent être sauvegardés avant.
 */
class ProjectSynchronizer
{
    /**
     * @param list<string>|null $previousFiles manifeste du déploiement précédent (null = premier déploiement)
     * @param callable(string): void|null $log
     */
    public function sync(
        string $source,
        string $target,
        PathMatcher $preserved,
        ?array $previousFiles,
        bool $deleteObsolete,
        ?string $backupDirectory,
        bool $dryRun = false,
        ?callable $log = null,
    ): SyncResult {
        $log ??= static fn (string $line) => null;
        $source = rtrim(str_replace('\\', '/', $source), '/');
        $target = rtrim(str_replace('\\', '/', $target), '/');
        $result = new SyncResult();

        if (!$dryRun && !is_dir($target) && !@mkdir($target, 0777, true)) {
            throw new LocalUpdateException(sprintf('Impossible de créer le répertoire du projet %s', $target));
        }

        foreach ($this->listFiles($source) as $relative) {
            if ($preserved->matches($relative)) {
                $result->preserved[] = $relative;
                continue;
            }
            $result->deployedFiles[] = $relative;
            $from = $source.'/'.$relative;
            $to = $target.'/'.$relative;

            if (is_dir($to)) {
                throw new LocalUpdateException(sprintf('%s est un dossier dans le projet mais un fichier dans l\'archive.', $relative));
            }
            if (is_file($to)) {
                if ($this->sameContent($from, $to)) {
                    ++$result->unchanged;
                    continue;
                }
                $result->modified[] = $relative;
                if (!$dryRun) {
                    $this->backup($to, $relative, $backupDirectory, $result);
                }
            } else {
                $result->added[] = $relative;
            }

            if (!$dryRun) {
                $this->copy($from, $to);
            }
        }

        if ($deleteObsolete && null !== $previousFiles) {
            $current = array_flip($result->deployedFiles);
            foreach ($previousFiles as $relative) {
                if (isset($current[$relative]) || $preserved->matches($relative) || !$this->isSafeRelative($relative)) {
                    continue;
                }
                $file = $target.'/'.$relative;
                if (!is_file($file)) {
                    continue;
                }
                $result->deleted[] = $relative;
                if (!$dryRun) {
                    $this->backup($file, $relative, $backupDirectory, $result);
                    if (!@unlink($file)) {
                        throw new LocalUpdateException(sprintf('Impossible de supprimer %s (fichier ouvert ?)', $file));
                    }
                    $this->removeEmptyParents(\dirname($file), $target);
                }
            }
        }

        sort($result->deployedFiles);
        foreach (['added' => '+', 'modified' => '~', 'deleted' => '-'] as $kind => $sign) {
            foreach (\array_slice($result->$kind, 0, 200) as $relative) {
                $log(sprintf('  %s %s', $sign, $relative));
            }
            if (\count($result->$kind) > 200) {
                $log(sprintf('  %s … et %d autres', $sign, \count($result->$kind) - 200));
            }
        }

        return $result;
    }

    /** @return list<string> chemins relatifs (séparateur /) */
    private function listFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = substr(str_replace('\\', '/', $file->getPathname()), \strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    private function sameContent(string $a, string $b): bool
    {
        return filesize($a) === filesize($b) && hash_file('xxh128', $a) === hash_file('xxh128', $b);
    }

    private function copy(string $from, string $to): void
    {
        $dir = \dirname($to);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new LocalUpdateException(sprintf('Impossible de créer le dossier %s', $dir));
        }
        if (!@copy($from, $to)) {
            throw new LocalUpdateException(sprintf('Impossible d\'écrire %s (fichier ouvert ou droits insuffisants ?)', $to));
        }
    }

    private function backup(string $file, string $relative, ?string $backupDirectory, SyncResult $result): void
    {
        if (null === $backupDirectory) {
            return;
        }
        $result->backupDirectory = $backupDirectory;
        $this->copy($file, rtrim($backupDirectory, '/').'/'.$relative);
    }

    private function isSafeRelative(string $relative): bool
    {
        return '' !== $relative && !str_starts_with($relative, '/') && !preg_match('#(^|/)\.\.(/|$)#', $relative) && !preg_match('#^[A-Za-z]:#', $relative);
    }

    private function removeEmptyParents(string $dir, string $target): void
    {
        $dir = str_replace('\\', '/', $dir);
        while (\strlen($dir) > \strlen($target) && str_starts_with($dir, $target.'/') && is_dir($dir) && [] === array_diff(scandir($dir) ?: [], ['.', '..'])) {
            @rmdir($dir);
            $dir = \dirname($dir);
        }
    }
}
