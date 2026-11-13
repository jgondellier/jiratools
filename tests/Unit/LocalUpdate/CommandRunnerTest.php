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
}
