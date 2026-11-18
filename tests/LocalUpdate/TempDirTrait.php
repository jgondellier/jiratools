<?php

namespace App\Tests\LocalUpdate;

trait TempDirTrait
{
    private ?string $tempRoot = null;

    protected function tmp(string $name): string
    {
        $this->tempRoot ??= sys_get_temp_dir().'/lu-test-'.bin2hex(random_bytes(4));

        return $this->tempRoot.'/'.$name;
    }

    /** @param array<string, string> $files */
    protected function makeZip(array $files): string
    {
        $path = $this->tmp('archive-'.bin2hex(random_bytes(3)).'.zip');
        @mkdir(\dirname($path), 0777, true);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /** @param array<string, string> $files */
    protected function writeFiles(string $root, array $files): void
    {
        foreach ($files as $name => $content) {
            @mkdir(\dirname($root.'/'.$name), 0777, true);
            file_put_contents($root.'/'.$name, $content);
        }
    }

    protected function tearDown(): void
    {
        if (null !== $this->tempRoot && is_dir($this->tempRoot)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tempRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tempRoot);
        }
        parent::tearDown();
    }
}
