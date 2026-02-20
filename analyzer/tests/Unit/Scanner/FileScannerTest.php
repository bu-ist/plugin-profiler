<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use PluginProfiler\Scanner\FileScanner;

class FileScannerTest extends TestCase
{
    private FileScanner $scanner;
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->scanner    = new FileScanner();
        $this->fixtureDir = __DIR__ . '/../../fixtures/sample-plugin';
    }

    public function testScan_WithValidDirectory_ReturnsFiles(): void
    {
        $files = $this->scanner->scan($this->fixtureDir);

        $this->assertNotEmpty($files);
        $this->assertContainsOnly('string', $files);
    }

    public function testScan_WithValidDirectory_ReturnsPhpFiles(): void
    {
        $files = $this->scanner->scan($this->fixtureDir);
        $phpFiles = array_filter($files, static fn (string $f) => str_ends_with($f, '.php'));

        $this->assertNotEmpty($phpFiles);
    }

    public function testScan_SkipsVendorDirectory(): void
    {
        $files = $this->scanner->scan($this->fixtureDir);

        foreach ($files as $file) {
            $this->assertStringNotContainsString('/vendor/', $file, "File from vendor/ should be excluded: $file");
        }
    }

    public function testScan_FindsBlockJsonFiles(): void
    {
        $files = $this->scanner->scan($this->fixtureDir);
        $blockJsonFiles = array_filter($files, static fn (string $f) => basename($f) === 'block.json');

        $this->assertCount(1, $blockJsonFiles, 'Expected exactly one block.json file');
    }

    public function testScan_FindsJavaScriptFiles(): void
    {
        $files = $this->scanner->scan($this->fixtureDir);
        $jsFiles = array_filter($files, static function (string $f): bool {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

            return in_array($ext, ['js', 'jsx', 'ts', 'tsx'], true);
        });

        $this->assertNotEmpty($jsFiles, 'Expected at least one JS file');
    }

    public function testScan_WithNonExistentDirectory_ReturnsEmptyArray(): void
    {
        $files = $this->scanner->scan('/this/does/not/exist');

        $this->assertSame([], $files);
    }

    public function testIdentifyMainPluginFile_WithHeader_ReturnsFile(): void
    {
        $files  = $this->scanner->scan($this->fixtureDir);
        $result = $this->scanner->identifyMainPluginFile($files);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('sample-plugin.php', $result);
    }

    public function testIdentifyMainPluginFile_WithNoPhpFiles_ReturnsNull(): void
    {
        $result = $this->scanner->identifyMainPluginFile(['block.json', 'src/index.js']);

        $this->assertNull($result);
    }

    public function testIdentifyMainPluginFile_WithNoHeader_ReturnsNull(): void
    {
        $files  = [$this->fixtureDir . '/includes/class-sample.php'];
        $result = $this->scanner->identifyMainPluginFile($files);

        $this->assertNull($result);
    }

    public function testFindBlockJsonFiles_ReturnsOnlyBlockJson(): void
    {
        $files  = $this->scanner->scan($this->fixtureDir);
        $result = $this->scanner->findBlockJsonFiles($files);

        foreach ($result as $file) {
            $this->assertSame('block.json', basename($file));
        }
    }

    public function testFindJavaScriptFiles_ReturnsOnlyJsFiles(): void
    {
        $files  = $this->scanner->scan($this->fixtureDir);
        $result = $this->scanner->findJavaScriptFiles($files);

        foreach ($result as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $this->assertContains($ext, ['js', 'jsx', 'ts', 'tsx']);
        }
    }
}
