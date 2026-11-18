<?php

namespace App\Tests\LocalUpdate;

use App\LocalUpdate\PathMatcher;
use App\LocalUpdate\ProjectSynchronizer;
use PHPUnit\Framework\TestCase;

class ProjectSynchronizerTest extends TestCase
{
    use TempDirTrait;

    public function testSyncPreservesBacksUpAndDeletesObsolete(): void
    {
        $source = $this->tmp('src');
        $target = $this->tmp('target');
        $backup = $this->tmp('backup');
        $this->writeFiles($source, [
            'src/Kernel.php' => 'new kernel',
            'src/New.php' => 'new',
            'README.md' => 'same',
            '.env.local' => 'from git',
            'var/cache/x' => 'cache',
        ]);
        $this->writeFiles($target, [
            'src/Kernel.php' => 'old kernel',
            'src/Old/Removed.php' => 'removed in git',
            'README.md' => 'same',
            '.env.local' => 'DATABASE_URL=local',
            'local-only.txt' => 'never deployed by the tool',
        ]);

        $result = (new ProjectSynchronizer())->sync(
            $source, $target, new PathMatcher(['.env.local', 'var/']),
            ['src/Kernel.php', 'src/Old/Removed.php', 'README.md', '.env.local'],
            true, $backup,
        );

        self::assertSame(['src/New.php'], $result->added);
        self::assertSame(['src/Kernel.php'], $result->modified);
        self::assertSame(['src/Old/Removed.php'], $result->deleted);
        self::assertSame(1, $result->unchanged);
        self::assertSame(['.env.local', 'var/cache/x'], $result->preserved);
        self::assertSame(['README.md', 'src/Kernel.php', 'src/New.php'], $result->deployedFiles);

        self::assertStringEqualsFile($target.'/src/Kernel.php', 'new kernel');
        self::assertStringEqualsFile($target.'/.env.local', 'DATABASE_URL=local');
        self::assertFileExists($target.'/local-only.txt');
        self::assertDirectoryDoesNotExist($target.'/src/Old');
        self::assertDirectoryDoesNotExist($target.'/var');
        self::assertStringEqualsFile($backup.'/src/Kernel.php', 'old kernel');
        self::assertStringEqualsFile($backup.'/src/Old/Removed.php', 'removed in git');
    }

    public function testDryRunWritesNothing(): void
    {
        $source = $this->tmp('src');
        $target = $this->tmp('target');
        $this->writeFiles($source, ['a.php' => 'new']);
        $this->writeFiles($target, ['a.php' => 'old', 'b.php' => 'obsolete']);

        $result = (new ProjectSynchronizer())->sync($source, $target, new PathMatcher([]), ['a.php', 'b.php'], true, null, true);

        self::assertSame(['a.php'], $result->modified);
        self::assertSame(['b.php'], $result->deleted);
        self::assertStringEqualsFile($target.'/a.php', 'old');
        self::assertFileExists($target.'/b.php');
    }

    public function testFirstDeploymentDeletesNothing(): void
    {
        $source = $this->tmp('src');
        $target = $this->tmp('target');
        $this->writeFiles($source, ['a.php' => 'a']);
        $this->writeFiles($target, ['b.php' => 'b']);

        $result = (new ProjectSynchronizer())->sync($source, $target, new PathMatcher([]), null, true, null);

        self::assertSame([], $result->deleted);
        self::assertFileExists($target.'/b.php');
    }
}
