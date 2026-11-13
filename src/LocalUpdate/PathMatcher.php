<?php

namespace App\LocalUpdate;

/**
 * Chemins protégés, relatifs à la racine du projet :
 *  - « var/ »        : le dossier et tout son contenu
 *  - « .env.local »  : ce fichier
 *  - « .env.*.local », « config/*.local.yaml » : motifs (* ne traverse pas les dossiers)
 *  - « *.log »       : motif sans « / » = nom de fichier, dans n'importe quel dossier
 */
final class PathMatcher
{
    /** @var list<string> */
    private array $patterns = [];

    /** @param iterable<string> $patterns */
    public function __construct(iterable $patterns)
    {
        foreach ($patterns as $pattern) {
            $pattern = ltrim(trim(str_replace('\\', '/', $pattern)), '/');
            if ('' !== $pattern) {
                $this->patterns[] = $pattern;
            }
        }
    }

    public function matches(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $basename = basename($path);

        foreach ($this->patterns as $pattern) {
            if (str_ends_with($pattern, '/')) {
                $dir = rtrim($pattern, '/');
                if ($path === $dir || str_starts_with($path, $dir.'/') || (str_contains($dir, '*') && $this->matchesDirGlob($dir, $path))) {
                    return true;
                }
                continue;
            }
            if (!str_contains($pattern, '/')) {
                if (fnmatch($pattern, $basename)) {
                    return true;
                }
                continue;
            }
            if (fnmatch($pattern, $path, FNM_PATHNAME) || str_starts_with($path, $pattern.'/')) {
                return true;
            }
        }

        return false;
    }

    private function matchesDirGlob(string $dir, string $path): bool
    {
        $parts = explode('/', $path);
        $depth = substr_count($dir, '/') + 1;

        return \count($parts) >= $depth && fnmatch($dir, implode('/', \array_slice($parts, 0, $depth)), FNM_PATHNAME);
    }
}
