<?php

declare(strict_types=1);

namespace PluginProfiler\Scanner;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FileScanner
{
    private const SKIP_DIRECTORIES = ['vendor', 'node_modules', '.git'];

    private const PHP_EXTENSIONS = ['php'];
    private const JS_EXTENSIONS  = ['js', 'jsx', 'ts', 'tsx'];

    /**
     * Recursively scan a directory and return paths to all relevant files.
     *
     * @return array<string>
     */
    public function scan(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files  = [];
        $flags  = RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS;
        $dirItr = new RecursiveDirectoryIterator($directory, $flags);
        $itr    = new RecursiveIteratorIterator($dirItr, RecursiveIteratorIterator::SELF_FIRST);

        /** @var SplFileInfo $fileInfo */
        foreach ($itr as $fileInfo) {
            if ($fileInfo->isDir()) {
                if (in_array($fileInfo->getBasename(), self::SKIP_DIRECTORIES, true)) {
                    $itr->next();
                    // Skip into the blocked directory by adjusting depth
                    // RecursiveIteratorIterator doesn't support skipping subtrees natively,
                    // so we track skipped dirs and filter on path instead.
                }
                continue;
            }

            // Filter out files inside skipped directories
            if ($this->isInsideSkippedDirectory($fileInfo->getRealPath(), $directory)) {
                continue;
            }

            $basename  = $fileInfo->getBasename();
            $extension = strtolower($fileInfo->getExtension());

            if ($basename === 'block.json') {
                $files[] = $fileInfo->getRealPath();
                continue;
            }

            if (in_array($extension, self::PHP_EXTENSIONS, true)) {
                $files[] = $fileInfo->getRealPath();
                continue;
            }

            if (in_array($extension, self::JS_EXTENSIONS, true)) {
                $files[] = $fileInfo->getRealPath();
            }
        }

        return $files;
    }

    /**
     * Find the main plugin file by locating the WordPress "Plugin Name:" header.
     *
     * @param array<string> $files
     */
    public function identifyMainPluginFile(array $files): ?string
    {
        foreach ($files as $filePath) {
            if (!str_ends_with($filePath, '.php')) {
                continue;
            }

            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                continue;
            }

            $header = fread($handle, 8192);
            fclose($handle);

            if ($header !== false && str_contains($header, 'Plugin Name:')) {
                return $filePath;
            }
        }

        return null;
    }

    /**
     * Return only block.json file paths from the given list.
     *
     * @param array<string> $files
     * @return array<string>
     */
    public function findBlockJsonFiles(array $files): array
    {
        return array_values(
            array_filter($files, static fn (string $f) => basename($f) === 'block.json')
        );
    }

    /**
     * Return only JavaScript/TypeScript file paths from the given list.
     *
     * @param array<string> $files
     * @return array<string>
     */
    public function findJavaScriptFiles(array $files): array
    {
        return array_values(
            array_filter($files, static function (string $f): bool {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

                return in_array($ext, self::JS_EXTENSIONS, true);
            })
        );
    }

    /**
     * Return only PHP file paths from the given list.
     *
     * @param array<string> $files
     * @return array<string>
     */
    public function findPhpFiles(array $files): array
    {
        return array_values(
            array_filter($files, static fn (string $f) => str_ends_with($f, '.php'))
        );
    }

    private function isInsideSkippedDirectory(string $realPath, string $baseDir): bool
    {
        foreach (self::SKIP_DIRECTORIES as $skip) {
            $pattern = DIRECTORY_SEPARATOR . $skip . DIRECTORY_SEPARATOR;
            if (str_contains($realPath, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
