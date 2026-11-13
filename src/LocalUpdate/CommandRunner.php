<?php

namespace App\LocalUpdate;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lance les commandes d'une application dans son répertoire.
 *
 * Remplacements :
 *  - « php » en début de commande et {php} : exécutable PHP (LOCAL_UPDATE_PHP_BINARY)
 *  - {composer} : commande Composer (LOCAL_UPDATE_COMPOSER)
 *  - {dir}      : répertoire du projet
 *
 * Les variables lues dans le .env de Jira Tools (APP_ENV, DATABASE_URL…) ne sont PAS transmises :
 * chaque application utilise son propre .env / .env.local.
 */
class CommandRunner
{
    /** Variables de l'application courante à ne jamais transmettre aux commandes */
    private const ISOLATED_VARIABLES = ['APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'DATABASE_URL', 'SYMFONY_DOTENV_VARS', 'SYMFONY_DOTENV_PATH', 'APP_RUNTIME', 'SHELL_VERBOSITY', 'KERNEL_CLASS', 'SYMFONY_DEPRECATIONS_HELPER'];

    public function __construct(
        private readonly string $phpBinary = '',
        private readonly string $composer = 'composer',
        private readonly int $timeout = 1800,
    ) {
    }

    public function getPhpBinary(): string
    {
        if ('' !== trim($this->phpBinary)) {
            return trim($this->phpBinary);
        }

        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }

    public function getComposer(): string
    {
        return trim($this->composer) ?: 'composer';
    }

    public function expand(string $command, string $directory): string
    {
        $php = $this->quote($this->getPhpBinary());
        $command = preg_replace('/^php(?=\s|$)/i', '{php}', trim($command));
        $composer = str_replace('{php}', $php, $this->getComposer());

        return strtr($command, ['{php}' => $php, '{composer}' => $composer, '{dir}' => $this->quote($directory)]);
    }

    /**
     * @param array<string, string>      $environment variables ajoutées
     * @param callable(string): void     $output      reçoit la sortie au fil de l'eau
     *
     * @return int code de retour
     */
    public function run(string $command, string $directory, array $environment, callable $output): int
    {
        $process = Process::fromShellCommandline($this->expand($command, $directory), $directory, $this->environment($environment), null, $this->timeout > 0 ? $this->timeout : null);

        try {
            return $process->run(static function (string $type, string $buffer) use ($output): void {
                $output($buffer);
            });
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            $output(sprintf("\nDélai dépassé (%d s) : commande interrompue. Augmentez LOCAL_UPDATE_COMMAND_TIMEOUT si besoin.\n", $this->timeout));

            return 124;
        } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
            $output("\n".$e->getMessage()."\n");

            return 127;
        }
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string|false> false = variable retirée
     */
    public function environment(array $extra = []): array
    {
        $isolated = self::ISOLATED_VARIABLES;
        foreach ([$_SERVER['SYMFONY_DOTENV_VARS'] ?? null, $_ENV['SYMFONY_DOTENV_VARS'] ?? null] as $loaded) {
            if (\is_string($loaded) && '' !== $loaded) {
                $isolated = array_merge($isolated, explode(',', $loaded));
            }
        }

        $env = array_fill_keys(array_unique($isolated), false);
        // Composer lancé depuis Apache : pas de dossier utilisateur
        if (false === getenv('COMPOSER_HOME') && false === getenv('HOME') && false === getenv('APPDATA')) {
            $env['COMPOSER_HOME'] = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'composer';
        }

        return $extra + $env;
    }

    private function quote(string $value): string
    {
        if ('' === $value || preg_match('/^[\w\/\\\\:.+-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }
}
