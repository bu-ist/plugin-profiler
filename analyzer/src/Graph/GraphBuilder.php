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
        // Polyfill bundles (e.g. js-dev/polyfills/)
        'polyfills',
        // Common WP plugin patterns for bundled libraries
        'libraries',
        // Well-known third-party SDKs bundled inside plugins
        'freemius',                 // Freemius monetisation SDK
        'plugin-update-checker',    // YahnisElsts update checker
        'tgm-plugin-activation',    // TGMPA required plugin activator
        'cmb2',                     // CMB2 custom meta boxes
        // Bundled email/template/CSS libraries (when appearing as top-level dirs)
        'phpmailer', 'swiftmailer',
        'tinymce', 'ckeditor', 'codemirror',
        'select2', 'chosen',
        'sweetalert2',
        'leaflet',
        'dropzone', 'plupload',
        'datatables',
        'fullcalendar',
        'tcpdf', 'fpdf', 'mpdf', 'dompdf',
        'htmlpurifier',
        'parsedown',
        'simplepie',
    ];

    /**
     * Filename prefixes (case-insensitive, dot or hyphen delimited) that
     * indicate a well-known JS library bundled directly in the plugin.
     * Matched against the basename without extension.
     * Examples: jquery.js, jquery-3.6.0.js, jquery.validate.js,
     *           backbone.js, underscore.js, lodash.js, bootstrap.js.
     *
     * The prefix check matches either an exact stem or any stem starting with
     * `{prefix}-` or `{prefix}.` — so `jquery` covers jquery.js,
     * jquery-3.6.0.js, jquery.validate.min.js, etc.
     */
    private const LIBRARY_FILENAME_PREFIXES = [
        // Core JS frameworks
        'jquery', 'backbone', 'underscore', 'lodash',
        'bootstrap', 'popper',
        'vue', 'preact', 'htmx', 'alpine',
        // React / webpack output
        'react', 'react-dom', 'runtime',
        'vendors', 'commons', 'manifest', 'workbox',
        // Date / time
        'moment', 'dayjs', 'luxon', 'flatpickr', 'pikaday',
        'datetimepicker', 'datepicker', 'daterangepicker', 'litepicker',
        'timeago',
        // Select / autocomplete
        'select2', 'chosen',
        // Data visualisation
        'd3', 'chart', 'highcharts', 'apexcharts', 'echarts',
        'plotly', 'chartist', 'morris', 'frappe', 'nvd3', 'c3',
        // Maps
        'leaflet', 'mapbox', 'openlayers',
        // Sliders / carousels
        'slick', 'swiper', 'owl', 'flickity', 'glide', 'splide',
        'bxslider', 'nivo', 'tiny-slider', 'revolution', 'unslider',
        // Animation
        'gsap', 'tweenmax', 'tweenlite', 'scrollmagic', 'animejs',
        'aos', 'wow', 'velocity', 'anime', 'particles',
        'typed', 'countup', 'odometer', 'vivus',
        // Editors
        'tinymce', 'codemirror', 'ckeditor', 'quill', 'summernote',
        'trumbowyg', 'froala', 'ace', 'monaco',
        'highlight', 'prism', 'medium-editor',
        // Modals / notifications / tooltips
        'sweetalert', 'sweetalert2', 'toastr', 'noty', 'notyf',
        'alertify', 'izitoast', 'tippy',
        'magnific', 'fancybox', 'photoswipe', 'lightgallery',
        'colorbox', 'featherlight', 'glightbox', 'venobox',
        'intro', 'shepherd',
        // Tables / grids
        'datatables', 'handsontable', 'tabulator',
        // File upload
        'dropzone', 'filepond', 'plupload', 'moxie', 'uppy',
        // Forms / validation / masking
        'parsley', 'inputmask', 'imask', 'cleave',
        'intl-tel-input', 'autosize',
        // Utility
        'axios', 'clipboard', 'sortable', 'dragula', 'hammer',
        'tether', 'modernizr', 'numeral', 'handlebars', 'mustache',
        'dompurify', 'qrcode', 'jsbarcode', 'jszip', 'jspdf',
        'fuse', 'lunr', 'zxcvbn', 'jsencrypt', 'sprintf',
        'localforage', 'socket', 'sockjs',
        // Polyfills
        'polyfill', 'respond', 'html5shiv', 'html5shim',
        'picturefill', 'es6-promise', 'whatwg-fetch',
        // WordPress-specific bundled JS
        'iris', 'farbtastic', 'thickbox', 'zeroclipboard',
        // Module loaders
        'requirejs', 'almond',
    ];

    /**
     * Exact filename stems (case-insensitive, extension stripped) that are
     * known scaffold boilerplate, framework runtime, or build-tool config
     * files — not developer business logic. Applied to JS files only.
     */
    private const SCAFFOLD_FILENAMES = [
        // Create React App boilerplate
        'reportwebvitals', 'setupTests', 'setuptests', 'setupproxy',
        'serviceworker', 'registerserviceworker',
        // Service workers / PWA
        'sw', 'workbox-sw', 'precache-manifest',
        // Polyfill entry bundles
        'polyfills', 'zone',
        // Build tool configs (Grunt, Gulp, Rollup, …)
        'gruntfile', 'gulpfile', 'rollup.config',
        'webpack.mix',      // Laravel Mix
        // Common bundler / test / lint configs
        'craco.config', 'vite.config', 'webpack.config',
        'babel.config', 'jest.config', 'postcss.config',
        'jest.setup', 'vitest.config', 'vitest.setup',
        'cypress.config', 'playwright.config',
        'eslint.config', 'prettier.config',
        'tsconfig', 'jsconfig',
        // Next.js pages scaffold
        '_app', '_document', '_error', 'middleware',
        'next.config',
        // Framework env files
        'environment', 'environment.prod',
        'svelte.config', 'nuxt.config', 'astro.config',
    ];

    /**
     * Exact filename stems (case-insensitive) for well-known bundled PHP
     * libraries that appear verbatim inside plugin directories.
     */
    private const LIBRARY_PHP_FILENAMES = [
        // PHPMailer (v5 and v6)
        'class.phpmailer', 'class.smtp', 'class.pop3',
        'phpmailer', 'class-phpmailer', 'phpmailer-exception',
        // Password hashing
        'passwordhash', 'class-phpass',
        // Feed / XML parsing
        'simplepie', 'class-simplepie',
        // Markdown parsers (very commonly bundled)
        'markdown', 'markdownextra',
        'parsedown', 'parsedown-extra',
        // Templating
        'mustache', 'twig',
        // ORM / DB
        'idiorm', 'paris', 'medoo',
        // HTTP
        'requests',
        // PDF generation
        'tcpdf', 'fpdf', 'fpdi', 'mpdf', 'dompdf',
        // Image processing
        'phpthumb', 'timthumb',
        // YAML
        'spyc',
        // CSS pre-processing
        'lessphp', 'scssphp',
        // XSS sanitisation
        'htmlpurifier', 'purifier',
        // Authentication / JWT
        'jwt', 'firebase-jwt', 'php-jwt',
        'oauth', 'oauth2',
        'recaptcha', 'class-recaptcha',
        // Date
        'carbon',
        // WordPress plugin SDKs (commonly bundled as single bootstrap files)
        'freemius',
        'plugin-update-checker', 'puc',
        'class-tgm-plugin-activation',
        // CSS minification / JS minification helpers
        'cssmin', 'jshrink', 'jsmin', 'minify',
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
                continue;
            }
            // Short-name JS symbols (1–2 chars) come from inline minified code
            // copy-pasted into a developer file. No developer writes a module-level
            // JS function named `b` or `c`, so tag these as library noise.
            if (
                in_array($node->type, ['js_function', 'js_class'], true)
                && strlen($node->label) <= 2
            ) {
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
            $lower = strtolower($segment);
            if (in_array($lower, self::LIBRARY_SEGMENTS, true)) {
                return true;
            }
            // Versioned library subdirectories: any segment matching `name-N.N[.N]`
            // (e.g., ext-2.1, jquery-1.11.0, bootstrap-4.0.0) is a vendored third-party
            // library frozen at a specific version — never developer-written code.
            if (preg_match('/^[a-z][a-z0-9_-]+-\d+\.\d+/i', $segment)) {
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
