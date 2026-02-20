<?php

declare(strict_types=1);

namespace PluginProfiler\Scanner;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FileScanner
{
    /** Always-skipped directory names regardless of ignore files. */
    private const SKIP_DIRECTORIES = ['vendor', 'node_modules', '.git'];

    private const PHP_EXTENSIONS = ['php'];
    private const JS_EXTENSIONS  = ['js', 'jsx', 'ts', 'tsx'];

    /** @var array<string> Compiled ignore patterns (relative glob-style strings). */
    private array $ignorePatterns = [];

    /**
     * Recursively scan a directory and return paths to all relevant files.
     *
     * Respects .gitignore and .profilerignore files in the plugin root.
     *
     * @return array<string>
     */
    public function scan(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $this->loadIgnorePatterns($directory);

        $files  = [];
        $flags  = RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS;
        $dirItr = new RecursiveDirectoryIterator($directory, $flags);
        $itr    = new RecursiveIteratorIterator($dirItr, RecursiveIteratorIterator::SELF_FIRST);

        /** @var SplFileInfo $fileInfo */
        foreach ($itr as $fileInfo) {
            $realPath = $fileInfo->getRealPath();
            $relative = $this->toRelative($realPath, $directory);

            if ($fileInfo->isDir()) {
                continue;
            }

            if ($this->isIgnored($relative, $directory)) {
                continue;
            }

            $basename  = $fileInfo->getBasename();
            $extension = strtolower($fileInfo->getExtension());

            if ($basename === 'block.json') {
                $files[] = $realPath;
                continue;
            }

            if (in_array($extension, self::PHP_EXTENSIONS, true)) {
                $files[] = $realPath;
                continue;
            }

            if (in_array($extension, self::JS_EXTENSIONS, true)) {
                $files[] = $realPath;
            }
        }

        return $files;
    }

    /**
     * Load ignore patterns from .gitignore and .profilerignore in the plugin root.
     * .profilerignore takes the same format as .gitignore.
     */
    private function loadIgnorePatterns(string $directory): void
    {
        $this->ignorePatterns = [];

        foreach (['.gitignore', '.profilerignore'] as $ignoreFile) {
            $path = $directory . DIRECTORY_SEPARATOR . $ignoreFile;
            if (!is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                // Skip comments
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                // Negation patterns (!) are not supported — skip them
                if (str_starts_with($line, '!')) {
                    continue;
                }
                $this->ignorePatterns[] = $line;
            }
        }
    }

    /**
     * Determine whether a relative path should be ignored.
     * Checks always-skip directories first, then parsed ignore patterns.
     */
    private function isIgnored(string $relative, string $baseDir): bool
    {
        $segments = explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $relative));

        // Always skip hard-coded directories and hidden directories (dot-prefixed)
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if (in_array($segment, self::SKIP_DIRECTORIES, true)) {
                return true;
            }
            // Skip hidden directories (.bu-baseline, .github, .circleci, etc.)
            // but NOT hidden files at the root (they may be config files we scan)
            if (str_starts_with($segment, '.') && $segment !== end($segments)) {
                return true;
            }
        }

        // Check loaded ignore patterns
        foreach ($this->ignorePatterns as $pattern) {
            if ($this->matchesPattern($relative, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a relative file path against a single gitignore-style pattern.
     * Supports trailing slashes (directory-only), leading slashes (root-anchored),
     * and basic * wildcards. Does not support ** or complex negation.
     */
    private function matchesPattern(string $relative, string $pattern): bool
    {
        // Normalise to forward slashes
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        // Directory-only pattern (trailing slash): match any path segment
        $dirOnly = str_ends_with($pattern, '/');
        $pattern = rtrim($pattern, '/');

        // Root-anchored pattern (leading slash): match from root only
        $anchored = str_starts_with($pattern, '/');
        $pattern  = ltrim($pattern, '/');

        // Convert glob wildcards to regex
        $regex = '#' . str_replace(
            ['\\*', '\\?', '\\['],
            ['[^/]*', '[^/]', '['],
            preg_quote($pattern, '#')
        ) . '#';

        if ($dirOnly) {
            // Pattern must match a directory component of the path
            $parts = explode('/', $relative);
            foreach ($parts as $i => $part) {
                $sub = implode('/', array_slice($parts, 0, $i + 1));
                if (preg_match($regex, $anchored ? $sub : $part)) {
                    return true;
                }
            }

            return false;
        }

        if ($anchored) {
            return (bool) preg_match($regex, $relative);
        }

        // Non-anchored: match against file name or any path component
        $basename = basename($relative);
        if (preg_match($regex, $basename)) {
            return true;
        }

        // Also try matching against each trailing sub-path
        $parts = explode('/', $relative);
        for ($i = 0; $i < count($parts); $i++) {
            $sub = implode('/', array_slice($parts, $i));
            if (preg_match($regex, $sub)) {
                return true;
            }
        }

        return false;
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

    private function toRelative(string $absolutePath, string $baseDir): string
    {
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $baseDir)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($baseDir)));
        }

        return $absolutePath;
    }
}
