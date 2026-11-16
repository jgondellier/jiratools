<?php

namespace App\LocalUpdate;

/**
 * Décompresse l'archive dans un dossier temporaire.
 * Les archives GitHub / GitLab / Bitbucket contiennent un unique dossier racine
 * (« owner-depot-1a2b3c4 ») : il est retiré, et son nom sert d'identifiant de version.
 */
class ZipExtractor
{
    /**
     * @return array{root: string, version: ?string} dossier contenant les fichiers du projet
     */
    public function extract(string $zipFile, string $destination, ?string $subdirectory = null): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new LocalUpdateException('L\'extension PHP zip n\'est pas activée (php.ini : extension=zip).');
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipFile);
        if (true !== $result) {
            $head = (string) @file_get_contents($zipFile, false, null, 0, 200);
            throw new LocalUpdateException(sprintf('Archive zip illisible (code %s)%s', $result, str_starts_with(ltrim($head), '<') ? ' : le serveur a renvoyé une page HTML (authentification ?)' : ''));
        }

        try {
            $topLevels = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                // Protection « zip slip » : aucun chemin absolu ni remontée de dossier
                if ('' === $name || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[A-Za-z]:#', $name)) {
                    throw new LocalUpdateException(sprintf('Chemin interdit dans l\'archive : %s', $name));
                }
                $topLevels[explode('/', $name, 2)[0]] = str_contains($name, '/');
            }
            if (!is_dir($destination) && !@mkdir($destination, 0777, true)) {
                throw new LocalUpdateException(sprintf('Impossible de créer le dossier %s', $destination));
            }
            if (!$zip->extractTo($destination)) {
                throw new LocalUpdateException('Échec de la décompression de l\'archive.');
            }
        } finally {
            $zip->close();
        }

        $root = rtrim(str_replace('\\', '/', $destination), '/');
        $version = null;
        if (1 === \count($topLevels) && reset($topLevels)) {
            $version = (string) array_key_first($topLevels);
            $root .= '/'.$version;
        }

        if (null !== $subdirectory && '' !== $subdirectory) {
            $root .= '/'.trim($subdirectory, '/');
            if (!is_dir($root)) {
                throw new LocalUpdateException(sprintf('Le sous-dossier « %s » n\'existe pas dans l\'archive.', $subdirectory));
            }
        }

        return ['root' => $root, 'version' => $version];
    }
}
