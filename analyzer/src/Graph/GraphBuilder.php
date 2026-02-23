<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class GraphBuilder
{
    /**
     * Directory segments (case-insensitive) that indicate bundled third-party
     * library code rather than developer-written code.
     * Nodes whose file paths contain one of these segments (as an exact path
     * component, not just a substring) are tagged `isLibrary = true` so the
     * frontend can offer a "developer code only" filter.
     *
     * Note: `lib/` and `libs/` are intentionally excluded — they are
     * commonly used for developer-written utility classes in WordPress plugins.
     * The top-level `vendor/` directory is already excluded by FileScanner;
     * this `vendor` entry catches sub-directories like `js/vendor/` or
     * `assets/vendor/` that contain bundled third-party JS.
     */
    private const LIBRARY_SEGMENTS = [
        'vendor',
        'third-party', 'thirdparty',
        'bower_components',
        'external', 'externals',
    ];

    /**
     * Filename prefixes (case-insensitive, dot or hyphen delimited) that
     * indicate a well-known JS library bundled directly in the plugin.
     * Matched against the basename without extension.
     * Examples: jquery.js, jquery-3.6.0.js, jquery.validate.js,
     *           backbone.js, underscore.js, lodash.js, bootstrap.js.
     */
    private const LIBRARY_FILENAME_PREFIXES = [
        // JS framework / utility libraries
        'jquery', 'backbone', 'underscore', 'lodash',
        'bootstrap', 'popper',
        'moment', 'flatpickr', 'pikaday',
        'select2', 'chosen',
        'd3', 'chart', 'highcharts',
        'slick', 'swiper',
        'tinymce', 'codemirror',
        'requirejs', 'almond',
        // React / webpack runtime chunks
        'react', 'react-dom', 'runtime',
    ];

    /**
     * Exact filename stems (case-insensitive, extension stripped) that are
     * known scaffold boilerplate or framework runtime files — not developer
     * business logic. Applied to JS files only.
     */
    private const SCAFFOLD_FILENAMES = [
        // Create React App boilerplate
        'reportwebvitals', 'setupTests', 'setuptests', 'setupproxy',
        'serviceworker', 'registerserviceworker',
        // Common CRA / Vite / webpack entry boilerplate
        'craco.config', 'vite.config', 'webpack.config',
        'babel.config', 'jest.config', 'postcss.config',
    ];

    /**
     * Exact filename stems (case-insensitive) for well-known bundled PHP
     * libraries that appear verbatim inside plugin directories.
     */
    private const LIBRARY_PHP_FILENAMES = [
        'class.phpmailer', 'class.smtp', 'class.pop3',     // PHPMailer v5
        'phpmailer',                                        // PHPMailer v6+
        'passwordhash', 'class-phpass',                    // phpass
        'simplepie',                                        // SimplePie RSS
        'markdown', 'markdownextra',                       // Markdown parsers
        'mustache',                                         // Mustache.php
        'idiorm', 'paris',                                  // ORM libs
        'requests',                                         // Requests for PHP
    ];

    /**
     * Build a validated Graph from the EntityCollection.
     *
     * - Drops edges whose source or target node ID does not exist.
     * - Reassigns edge IDs sequentially ("e_0", "e_1", ...).
     * - Tags nodes with isLibrary = true when their file is in a bundled
     *   library directory (lib/, libs/, third-party/, …).
     */
    public function build(EntityCollection $collection, PluginMetadata $meta): Graph
    {
        $nodes   = $collection->getAllNodes();
        $nodeIds = array_keys($nodes);
        $nodeSet = array_flip($nodeIds);

        $validEdges   = [];
        $edgeSequence = 0;

        foreach ($collection->getAllEdges() as $edge) {
            if (!isset($nodeSet[$edge->source]) || !isset($nodeSet[$edge->target])) {
                continue;
            }

            $validEdges[] = new Edge(
                id: 'e_' . $edgeSequence++,
                source: $edge->source,
                target: $edge->target,
                type: $edge->type,
                label: $edge->label,
            );
        }

        // Tag library nodes so the frontend can filter them
        foreach ($nodes as $node) {
            if ($this->isLibraryFile($node->file)) {
                $node->isLibrary = true;
            }
        }

        return new Graph(
            nodes: array_values($nodes),
            edges: $validEdges,
            plugin: $meta,
        );
    }

    /**
     * Return true when the file is from a bundled third-party library or
     * scaffold boilerplate rather than developer business logic.
     *
     * Four signals are checked (any one is sufficient):
     *  1. A directory segment matches LIBRARY_SEGMENTS (vendor/, third-party/, …).
     *  2. A JS filename stem starts with a known library prefix (LIBRARY_FILENAME_PREFIXES).
     *  3. A JS filename stem exactly matches a known scaffold file (SCAFFOLD_FILENAMES).
     *  4. A PHP filename stem exactly matches a known bundled PHP library (LIBRARY_PHP_FILENAMES).
     */
    private function isLibraryFile(string $filePath): bool
    {
        $normalized = str_replace(['\\', DIRECTORY_SEPARATOR], '/', $filePath);
        $parts      = explode('/', $normalized);

        // 1. Directory-segment check (excludes the filename itself)
        $dirs = array_slice($parts, 0, count($parts) - 1);
        foreach ($dirs as $segment) {
            if (in_array(strtolower($segment), self::LIBRARY_SEGMENTS, true)) {
                return true;
            }
        }

        $filename = strtolower(end($parts));
        $stem     = strtolower((string) pathinfo($filename, PATHINFO_FILENAME));

        // 2 & 3. JS-specific filename checks
        if (str_ends_with($filename, '.js')) {
            // 2. Prefix match (jquery.js, jquery-3.6.0.js, bootstrap.bundle.js, …)
            foreach (self::LIBRARY_FILENAME_PREFIXES as $prefix) {
                if ($stem === $prefix || str_starts_with($stem, $prefix . '-') || str_starts_with($stem, $prefix . '.')) {
                    return true;
                }
            }
            // 3. Exact scaffold filename (reportWebVitals.js, setupTests.js, …)
            if (in_array($stem, self::SCAFFOLD_FILENAMES, true)) {
                return true;
            }
        }

        // 4. PHP known-library filename check
        if (str_ends_with($filename, '.php') && in_array($stem, self::LIBRARY_PHP_FILENAMES, true)) {
            return true;
        }

        return false;
    }
}
