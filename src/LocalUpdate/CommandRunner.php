<?php

namespace App\LocalUpdate;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lance les commandes d'une application dans son répertoire.
 *
 * Remplacements :
 *  - « php » en début de commande et {php} : exécutable PHP (APP_UPDATE_PHP_BINARY)
 *  - {composer} : Composer — APP_UPDATE_COMPOSER, à défaut « php composer.phar » si composer.phar
 *                 est à la racine de Jira Tools ou à côté de php.exe, sinon la commande « composer » du PATH
 *  - {dir}      : répertoire du projet
 *
 * Les variables lues dans le .env de Jira Tools (APP_ENV, DATABASE_URL…) ne sont PAS transmises :
 * chaque application utilise son propre .env / .env.local.
 */
class CommandRunner
{
    /** Variables de l'application courante à ne jamais transmettre aux commandes */
    private const ISOLATED_VARIABLES = ['APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'DATABASE_URL', 'SYMFONY_DOTENV_VARS', 'SYMFONY_DOTENV_PATH', 'APP_RUNTIME', 'SHELL_VERBOSITY', 'KERNEL_CLASS', 'SYMFONY_DEPRECATIONS_HELPER', 'APP_CACHE_DIR', 'APP_LOG_DIR', 'MESSENGER_TRANSPORT_DSN', 'MAILER_DSN', 'DEFAULT_URI', 'LU_PHP', 'LU_CONSOLE', 'LU_LOG', 'LU_RUN'];

    public function __construct(
        #[Autowire('%env(default::APP_UPDATE_PHP_BINARY)%')]
        private readonly ?string $phpBinary = '',
        #[Autowire('%env(default::APP_UPDATE_COMPOSER)%')]
        private readonly ?string $composer = '',
        #[Autowire('%env(default::APP_UPDATE_COMMAND_TIMEOUT)%')]
        string|int|null $timeout = 1800,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory = '',
    ) {
        // Variable absente ou vide : 1800 s
        $this->timeout = null === $timeout || '' === $timeout ? 1800 : max(0, (int) $timeout);
    }

    private readonly int $timeout;

    public function getPhpBinary(): string
    {
        if ('' !== trim((string) $this->phpBinary)) {
            return trim((string) $this->phpBinary);
        }

        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }

    public function getComposer(): string
    {
        return $this->describeComposer()['command'];
    }

    /**
     * Commande Composer utilisée et sa provenance.
     *
     * @return array{command: string, source: string, phar: ?string}
     */
    public function describeComposer(): array
    {
        $configured = trim((string) $this->composer);
        if ('' !== $configured) {
            // Un .phar lancé seul ouvre sous Windows « Comment voulez-vous ouvrir ce fichier ? » : PHP ajouté
            return ['command' => $this->withPhp($configured), 'source' => 'APP_UPDATE_COMPOSER', 'phar' => null];
        }

        // composer.phar lancé avec PHP : racine de Jira Tools, puis dossier de php.exe
        $php = $this->getPhpBinary();
        foreach (array_filter([$this->projectDirectory, 'php' !== $php ? \dirname($php) : null]) as $dir) {
            $phar = rtrim(str_replace('\\', '/', $dir), '/').'/composer.phar';
            if (is_file($phar)) {
                return ['command' => '{php} '.$this->quote($phar), 'source' => 'composer.phar trouvé', 'phar' => $phar];
            }
        }

        // PATH : composer.bat / composer.exe (installeur Windows), sinon composer.phar lancé avec PHP
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $dir = rtrim(str_replace('\\', '/', trim($dir, " \"")), '/');
            if ('' === $dir) {
                continue;
            }
            foreach ('\\' === \DIRECTORY_SEPARATOR ? ['composer.bat', 'composer.cmd', 'composer.exe'] : ['composer'] as $launcher) {
                if (is_file($dir.'/'.$launcher)) {
                    return ['command' => 'composer', 'source' => 'commande du PATH ('.$dir.'/'.$launcher.')', 'phar' => null];
                }
            }
            if (is_file($dir.'/composer.phar')) {
                return ['command' => '{php} '.$this->quote($dir.'/composer.phar'), 'source' => 'composer.phar trouvé dans le PATH', 'phar' => $dir.'/composer.phar'];
            }
        }

        return ['command' => 'composer', 'source' => 'commande du PATH', 'phar' => null];
    }

    /**
     * Commande qui commence par un fichier .phar : préfixée par PHP ({php}), sinon Windows
     * demande avec quelle application ouvrir le fichier.
     */
    public function withPhp(string $command): string
    {
        return (string) preg_replace('/^\s*("[^"]+\.phar"|[^\s"]+\.phar)(?=\s|$)/i', '{php} $1', $command);
    }

    public function expand(string $command, string $directory): string
    {
        $php = $this->quote($this->getPhpBinary());
        $command = preg_replace('/^php(?=\s|$)/i', '{php}', $this->withPhp(trim($command)));
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
            $output(sprintf("\nDélai dépassé (%d s) : commande interrompue. Augmentez APP_UPDATE_COMMAND_TIMEOUT si besoin.\n", $this->timeout));

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

        // Variables du système (PATH, proxy, dossier utilisateur…) transmises explicitement :
        // depuis Apache, Symfony Process ne garderait que celles présentes dans $_SERVER
        return $extra + $env + self::systemEnvironment();
    }

    /** @return array<string, string> */
    public static function systemEnvironment(): array
    {
        $env = getenv();

        return \is_array($env) ? $env : [];
    }

    private function quote(string $value): string
    {
        if ('' === $value || preg_match('/^[\w\/\\\\:.+-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }
}
