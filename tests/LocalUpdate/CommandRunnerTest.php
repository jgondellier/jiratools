<?php

namespace App\Tests\LocalUpdate;

use App\LocalUpdate\CommandRunner;
use PHPUnit\Framework\TestCase;

class CommandRunnerTest extends TestCase
{
    public function testExpandsPlaceholders(): void
    {
        $runner = new CommandRunner('C:/Program Files/php/php.exe', '{php} C:/tools/composer.phar');

        self::assertSame('"C:/Program Files/php/php.exe" bin/console cache:clear', $runner->expand('php bin/console cache:clear', '/x'));
        self::assertSame('"C:/Program Files/php/php.exe" C:/tools/composer.phar install', $runner->expand('{composer} install', '/x'));
        self::assertSame('phpunit', $runner->expand('phpunit', '/x'));
    }

    public function testDoesNotLeakOwnEnvironment(): void
    {
        $_ENV['SYMFONY_DOTENV_VARS'] = 'DATABASE_URL,JIRA_BASE_URL';
        $env = (new CommandRunner())->environment(['APP_ENV' => 'dev']);
        unset($_ENV['SYMFONY_DOTENV_VARS']);

        self::assertFalse($env['DATABASE_URL']);
        self::assertFalse($env['JIRA_BASE_URL']);
        self::assertSame('dev', $env['APP_ENV']);
    }

    public function testRunsInDirectoryAndReturnsExitCode(): void
    {
        $runner = new CommandRunner(PHP_BINARY);
        $output = '';
        $code = $runner->run('php -r "echo getcwd(); exit(3);"', sys_get_temp_dir(), [], function (string $b) use (&$output) { $output .= $b; });

        self::assertSame(3, $code);
        self::assertSame(realpath(sys_get_temp_dir()), realpath($output));
    }

    public function testComposerPharIsRunWithPhp(): void
    {
        $path = getenv('PATH');
        putenv('PATH='.sys_get_temp_dir().'/jiratools_vide_'.bin2hex(random_bytes(3)));   // pas de Composer du poste
        try {
            $dir = sys_get_temp_dir().'/lu-composer-'.bin2hex(random_bytes(3));
            mkdir($dir);
            try {
                // Rien de trouvé : commande « composer » du PATH
                self::assertSame('composer', (new CommandRunner('/opt/php/php', '', 1800, $dir))->getComposer());

                // composer.phar à la racine de Jira Tools : lancé avec le PHP configuré
                touch($dir.'/composer.phar');
                $runner = new CommandRunner('/opt/php/php', '', 1800, $dir);
                self::assertSame('/opt/php/php '.$dir.'/composer.phar install --no-interaction', $runner->expand('{composer} install --no-interaction', $dir));
                self::assertSame('composer.phar trouvé', $runner->describeComposer()['source']);
                // écrit directement : « php composer.phar install »
                self::assertSame('/opt/php/php composer.phar install', $runner->expand('php composer.phar install', $dir));

                // APP_UPDATE_COMPOSER renseigné : prioritaire
                self::assertSame('/opt/php/php C:/tools/composer.phar install', (new CommandRunner('/opt/php/php', '{php} C:/tools/composer.phar', 1800, $dir))->expand('{composer} install', $dir));
            } finally {
                @unlink($dir.'/composer.phar');
                @rmdir($dir);
            }
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function testPharWithoutPhpIsAlwaysRunWithPhp(): void
    {
        $dir = sys_get_temp_dir();
        // APP_UPDATE_COMPOSER = chemin du .phar seul (sans {php})
        $runner = new CommandRunner('C:/EasyPHP/php/php.exe', 'C:/composer/composer.phar', 1800, $dir);
        self::assertSame('C:/EasyPHP/php/php.exe C:/composer/composer.phar install', $runner->expand('{composer} install', $dir));
        self::assertSame('C:/EasyPHP/php/php.exe "C:/Program Files/composer.phar" -V', (new CommandRunner('C:/EasyPHP/php/php.exe', '"C:/Program Files/composer.phar"', 1800, $dir))->expand('{composer} -V', $dir));

        // Commande saisie dans les paramètres : « composer.phar install »
        $runner = new CommandRunner('C:/EasyPHP/php/php.exe', '', 1800, $dir);
        self::assertSame('C:/EasyPHP/php/php.exe composer.phar install --no-interaction', $runner->expand('composer.phar install --no-interaction', $dir));
        self::assertSame('C:/EasyPHP/php/php.exe composer.phar install', $runner->expand('php composer.phar install', $dir), 'déjà avec php : inchangé');
        self::assertSame('C:/EasyPHP/php/php.exe bin/console cache:clear', $runner->expand('php bin/console cache:clear', $dir));
    }

    public function testComposerPharFoundInPathIsRunWithPhp(): void
    {
        $bin = sys_get_temp_dir().'/jiratools_path_'.bin2hex(random_bytes(3));
        mkdir($bin);
        touch($bin.'/composer.phar');
        $path = getenv('PATH');
        putenv('PATH='.$bin.PATH_SEPARATOR.$path);
        try {
            $runner = new CommandRunner('/opt/php/php', '', 1800, sys_get_temp_dir().'/sans_composer_'.bin2hex(random_bytes(3)));
            self::assertSame('{php} '.$bin.'/composer.phar', $runner->getComposer());
            self::assertSame('composer.phar trouvé dans le PATH', $runner->describeComposer()['source']);
        } finally {
            putenv('PATH='.$path);
            unlink($bin.'/composer.phar');
            rmdir($bin);
        }
    }
}
