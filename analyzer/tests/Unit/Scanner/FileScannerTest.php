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

    public function testScan_SkipsMinifiedJsFiles(): void
    {
        $files = $this->scanner->scan($this->fixtureDir);

        foreach ($files as $file) {
            $this->assertStringNotContainsString('.min.js', $file, "Minified file should be excluded: $file");
            $this->assertStringNotContainsString('.build.js', $file, "Build bundle should be excluded: $file");
        }
    }

    public function testScan_StillFindsSourceJsAfterMinifiedExclusion(): void
    {
        $files  = $this->scanner->scan($this->fixtureDir);
        $jsFiles = array_filter($files, static fn (string $f) => str_ends_with($f, '.js'));

        // src/index.js should still be present; minified files excluded
        $basenames = array_map('basename', $jsFiles);
        $this->assertContains('index.js', $basenames, 'Source JS files should still be scanned');
    }

    public function testScan_SkipsJsFilesWithSourceMapFooter(): void
    {
        // compiled.js has a `//# sourceMappingURL=` footer — it is a compiled bundle
        // regardless of its non-minified filename and must be excluded from analysis.
        $files     = $this->scanner->scan($this->fixtureDir);
        $basenames = array_map('basename', $files);

        $this->assertNotContains('compiled.js', $basenames, 'Compiled bundle with source-map footer should be excluded');
    }

    public function testScan_SkipsBuildDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tmpDir . '/build', 0777, true);
        file_put_contents($tmpDir . '/build/app.js', '// compiled output');
        file_put_contents($tmpDir . '/main.php', '<?php ?>');

        $files     = $this->scanner->scan($tmpDir);
        $basenames = array_map('basename', $files);

        $this->assertNotContains('app.js', $basenames, 'JS in build/ should be excluded');
        $this->cleanupTempDir($tmpDir);
    }

    public function testScan_SkipsDistDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tmpDir . '/dist', 0777, true);
        file_put_contents($tmpDir . '/dist/bundle.js', '// dist output');
        file_put_contents($tmpDir . '/main.php', '<?php ?>');

        $files     = $this->scanner->scan($tmpDir);
        $basenames = array_map('basename', $files);

        $this->assertNotContains('bundle.js', $basenames, 'JS in dist/ should be excluded');
        $this->cleanupTempDir($tmpDir);
    }

    public function testScan_SkipsBowerComponentsDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tmpDir . '/bower_components/jquery', 0777, true);
        file_put_contents($tmpDir . '/bower_components/jquery/jquery.js', '// jQuery');
        file_put_contents($tmpDir . '/main.php', '<?php ?>');

        $files     = $this->scanner->scan($tmpDir);
        $basenames = array_map('basename', $files);

        $this->assertNotContains('jquery.js', $basenames, 'JS in bower_components/ should be excluded');
        $this->cleanupTempDir($tmpDir);
    }

    public function testScan_SkipsFileWithGeneratedByMarker(): void
    {
        $tmpDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/generated.js', "// Generated by TypeScript\nconst x = 1;\n");

        $files     = $this->scanner->scan($tmpDir);
        $basenames = array_map('basename', $files);

        $this->assertNotContains('generated.js', $basenames, 'File with "Generated by" marker should be excluded');
        $this->cleanupTempDir($tmpDir);
    }

    public function testScan_SkipsFileWithDoNotEditMarker(): void
    {
        $tmpDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/auto.js', "// DO NOT EDIT\nconst x = 1;\n");

        $files     = $this->scanner->scan($tmpDir);
        $basenames = array_map('basename', $files);

        $this->assertNotContains('auto.js', $basenames, 'File with "DO NOT EDIT" marker should be excluded');
        $this->cleanupTempDir($tmpDir);
    }

    public function testScan_SkipsFileWithEslintDisableMarker(): void
    {
        $tmpDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/cra-build.js', "/* eslint-disable */\nconst x = 1;\n");

        $files     = $this->scanner->scan($tmpDir);
        $basenames = array_map('basename', $files);

        $this->assertNotContains('cra-build.js', $basenames, 'CRA build output with eslint-disable marker should be excluded');
        $this->cleanupTempDir($tmpDir);
    }

    public function testScan_SkipsFileWithHighAverageLineLength(): void
    {
        $tmpDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        // 5 lines each 135 chars long → average of 135, well above the 110-char threshold
        $longLine = str_repeat('a', 135);
        file_put_contents($tmpDir . '/uglified.js', implode("\n", array_fill(0, 5, $longLine)));

        $files     = $this->scanner->scan($tmpDir);
        $basenames = array_map('basename', $files);

        $this->assertNotContains('uglified.js', $basenames, 'File with avg line length >110 should be excluded');
        $this->cleanupTempDir($tmpDir);
    }

    public function testScan_IncludesFileWithNormalLineLength(): void
    {
        $tmpDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $content = "const x = 1;\nconst y = 2;\nfunction add(a, b) { return a + b; }\nexport default add;\n";
        file_put_contents($tmpDir . '/app.js', $content);

        $files     = $this->scanner->scan($tmpDir);
        $basenames = array_map('basename', $files);

        $this->assertContains('app.js', $basenames, 'Normal source JS with short lines should be included');
        $this->cleanupTempDir($tmpDir);
    }

    private function cleanupTempDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f->getRealPath()) : unlink((string) $f->getRealPath());
        }
        rmdir($dir);
    }
}
