<?php

namespace App\Tests\LocalUpdate;

use App\LocalUpdate\LocalUpdateException;
use App\LocalUpdate\ZipExtractor;
use PHPUnit\Framework\TestCase;

class ZipExtractorTest extends TestCase
{
    use TempDirTrait;

    public function testStripsSingleRootFolder(): void
    {
        $zip = $this->makeZip(['acme-catalogue-1a2b3c4/composer.json' => '{}', 'acme-catalogue-1a2b3c4/src/A.php' => '<?php']);
        $result = (new ZipExtractor())->extract($zip, $this->tmp('out'));

        self::assertSame('acme-catalogue-1a2b3c4', $result['version']);
        self::assertFileExists($result['root'].'/src/A.php');
    }

    public function testSubdirectory(): void
    {
        $zip = $this->makeZip(['repo-abc/apps/shop/index.php' => 'x', 'repo-abc/README.md' => 'r']);
        $result = (new ZipExtractor())->extract($zip, $this->tmp('out'), 'apps/shop');

        self::assertFileExists($result['root'].'/index.php');
        self::assertFileDoesNotExist($result['root'].'/README.md');
    }

    public function testRejectsZipSlip(): void
    {
        $zip = $this->makeZip(['../evil.php' => 'x']);
        $this->expectException(LocalUpdateException::class);
        (new ZipExtractor())->extract($zip, $this->tmp('out'));
    }

    public function testHtmlInsteadOfZip(): void
    {
        $file = $this->tmp('page').'.zip';
        @mkdir(\dirname($file), 0777, true);
        file_put_contents($file, '<html>login</html>');
        $this->expectExceptionMessage('page HTML');
        (new ZipExtractor())->extract($file, $this->tmp('out'));
    }
}
