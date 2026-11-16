<?php

namespace App\Tests\Unit\LocalUpdate;

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
    }
}
