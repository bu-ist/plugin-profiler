<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

/**
 * Resolves cross-language edges between JavaScript call-sites and their PHP handlers.
 *
 * This is the tool's unique differentiator: no other static-analysis tool in the
 * WordPress ecosystem connects client-side API calls to their server-side handlers.
 *
 * Runs after all PHP and JS visitors have populated the EntityCollection, before
 * GraphBuilder consolidates the results into a final Graph.
 *
 * Three cross-language edge types are detected:
 *
 *   calls_endpoint       js_api_call   → rest_endpoint      (WordPress REST API)
 *   calls_ajax_handler   fetch_call    → ajax_handler        (wp_ajax_* handlers)
 *                        axios_call    → ajax_handler        (wp_ajax_* handlers)
 *   js_block_matches_php gutenberg_block (JS) → gutenberg_block (PHP/block.json)
 *
 * Matching strategy for REST routes:
 *   Path matching is literal after normalisation (strip scheme, host, wp-json/
 *   prefix, query string, leading/trailing slashes, lowercase).  Dynamic segments
 *   in register_rest_route() patterns like `(?P<id>\d+)` are stripped to produce a
 *   static prefix — a JS path that starts with that prefix is considered a match.
 *
 * Matching strategy for AJAX handlers:
 *   The `action` query parameter is extracted from the URL string literal that the
 *   JS visitor recorded.  Dynamic URLs constructed at runtime that cannot be
 *   statically resolved simply produce no edge — a safe, silent miss.
 */
final class CrossReferenceResolver
{
    public function resolve(EntityCollection $collection): void
    {
        $nodes = $collection->getAllNodes();

        $restEndpoints = $this->collectByType($nodes, 'rest_endpoint');
        $ajaxHandlers  = $this->collectByType($nodes, 'ajax_handler');
        $phpBlocks     = $this->collectByTypeAndLanguage($nodes, 'gutenberg_block', 'php');

        foreach ($nodes as $node) {
            match ($node->type) {
                'js_api_call'     => $this->matchRestEndpoint($node, $restEndpoints, $collection),
                'fetch_call',
                'axios_call'      => $this->matchAjaxHandler($node, $ajaxHandlers, $collection),
                'gutenberg_block' => $this->matchPhpBlock($node, $phpBlocks, $collection),
                default           => null,
            };
        }
    }

    // ── REST endpoint matching ─────────────────────────────────────────────────

    /**
     * Match a js_api_call to a rest_endpoint node.
     *
     * The JS visitor stores the API path in `metadata.route` (e.g. `/sample/v1/items`).
     * The PHP visitor stores the registered route in `metadata.route` as the
     * concatenation of namespace + route arguments to register_rest_route().
     *
     * Dynamic segments like `(?P<id>\d+)` in the PHP route are stripped so that
     * `/sample/v1/items/42` matches a route registered as `/sample/v1/items/(?P<id>\d+)`.
     *
     * @param array<string, Node> $restEndpoints
     */
    private function matchRestEndpoint(Node $jsNode, array $restEndpoints, EntityCollection $collection): void
    {
        $jsPath = $this->normalizePath((string) ($jsNode->metadata['route'] ?? ''));
        if ($jsPath === '') {
            return;
        }

        foreach ($restEndpoints as $endpoint) {
            $phpPath = $this->normalizePath((string) ($endpoint->metadata['route'] ?? ''));
            if ($phpPath === '') {
                continue;
            }

            // Strip regex dynamic segments to get the static route prefix.
            // "sample/v1/items/(?P<id>\d+)" → "sample/v1/items"
            // Flags: i=case-insensitive (path is lowercased), .*$ captures the
            // full tail including when the segment falls at end-of-string.
            $staticPrefix = preg_replace('/\/?\(\?P?[<\'][^)]+\).*$/i', '', $phpPath) ?? $phpPath;

            if ($jsPath === $phpPath
                || $jsPath === $staticPrefix
                || str_starts_with($jsPath, $staticPrefix . '/')) {
                $collection->addEdge(Edge::make($jsNode->id, $endpoint->id, 'calls_endpoint', 'REST'));
                return; // First match wins — one call maps to one endpoint
            }
        }
    }

    // ── AJAX handler matching ──────────────────────────────────────────────────

    /**
     * Match a fetch_call or axios_call to an ajax_handler node.
     *
     * The JS visitor records the URL string in `metadata.route`.  We check whether
     * it contains `admin-ajax.php` and, if so, extract the `action` query parameter.
     * The PHP visitor stores the full hook name in `metadata.hook_name`
     * (e.g. `wp_ajax_my_action`); we strip the prefix to get the bare action name.
     *
     * Dynamically-constructed URLs where the action cannot be statically extracted
     * produce no edge — a safe, silent miss.
     *
     * @param array<string, Node> $ajaxHandlers
     */
    private function matchAjaxHandler(Node $jsNode, array $ajaxHandlers, EntityCollection $collection): void
    {
        $url = (string) ($jsNode->metadata['route'] ?? '');
        if (!str_contains($url, 'admin-ajax.php')) {
            return;
        }

        $action = $this->extractQueryParam($url, 'action');
        if ($action === null) {
            return; // Action not statically detectable from the URL literal
        }

        foreach ($ajaxHandlers as $handler) {
            $hookName   = (string) ($handler->metadata['hook_name'] ?? '');
            // Strip "wp_ajax_" or "wp_ajax_nopriv_" to recover the bare action name
            $bareAction = (string) preg_replace('/^wp_ajax_(nopriv_)?/', '', $hookName);
            if ($bareAction === $action) {
                $collection->addEdge(
                    Edge::make($jsNode->id, $handler->id, 'calls_ajax_handler', 'AJAX')
                );
                return; // First match wins
            }
        }
    }

    // ── Gutenberg block matching ───────────────────────────────────────────────

    /**
     * Match a JS gutenberg_block to its PHP or block.json counterpart.
     *
     * Both PHP and JS visitors emit gutenberg_block nodes with `metadata.block_name`
     * (e.g. `my-plugin/hero-section`).  When a JS block shares a block_name with a
     * PHP/block.json block, they represent the two halves of the same Gutenberg block
     * registration and deserve a visual connection.
     *
     * @param array<string, Node> $phpBlocks  PHP- or block.json-sourced block nodes
     */
    private function matchPhpBlock(Node $jsNode, array $phpBlocks, EntityCollection $collection): void
    {
        if (!$this->isJavaScriptFile($jsNode->file ?? '')) {
            return; // Only originate edges from JS-sourced block nodes
        }

        $jsBlockName = (string) ($jsNode->metadata['block_name'] ?? '');
        if ($jsBlockName === '') {
            return;
        }

        foreach ($phpBlocks as $phpBlock) {
            if (($phpBlock->metadata['block_name'] ?? '') === $jsBlockName) {
                $collection->addEdge(
                    Edge::make($jsNode->id, $phpBlock->id, 'js_block_matches_php', 'block registration')
                );
                return;
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Return a subset of the node map containing only nodes of the given type.
     *
     * @param array<string, Node> $nodes
     * @return array<string, Node>
     */
    private function collectByType(array $nodes, string $type): array
    {
        return array_filter($nodes, static fn (Node $n) => $n->type === $type);
    }

    /**
     * Return nodes of the given type where the source language matches.
     * Language is inferred from the file extension: .js/.jsx/.ts/.tsx → JS, else PHP.
     *
     * @param array<string, Node> $nodes
     * @return array<string, Node>
     */
    private function collectByTypeAndLanguage(array $nodes, string $type, string $language): array
    {
        return array_filter($nodes, function (Node $n) use ($type, $language) {
            if ($n->type !== $type) {
                return false;
            }
            $isJs = $this->isJavaScriptFile($n->file ?? '');
            return $language === 'php' ? !$isJs : $isJs;
        });
    }

    /**
     * Normalise a URL or path to a bare, lowercase, slash-stripped route string.
     *
     * Strips:
     *  - scheme + host  (https://example.com)
     *  - WordPress REST base prefix  (wp-json/)
     *  - URL query string and fragment (but NOT `?` inside PHP regex groups like `(?P<id>...)`)
     *  - leading / trailing slashes
     *
     * The negative lookbehind `(?<!\()` ensures that `?` inside regex-syntax groups
     * (e.g. the `?` in `(?P<id>\d+)`) is never treated as a query-string delimiter.
     *
     * @example "/wp/v2/posts?_embed=1"                       → "wp/v2/posts"
     * @example "https://example.com/wp-json/sample/v1/items"  → "sample/v1/items"
     * @example "sample/v1/items/(?P<id>\d+)"                  → "sample/v1/items/(?p<id>\d+)"
     */
    private function normalizePath(string $raw): string
    {
        $raw = (string) preg_replace('#^https?://[^/]+#', '', $raw);
        $raw = (string) preg_replace('#^/?wp-json/#', '', $raw);
        // Strip URL query string / fragment, but not `?` inside regex groups like (?P<...>)
        $raw = (string) preg_replace('/(?<!\()[?#].*$/', '', $raw);

        return strtolower(trim($raw, '/'));
    }

    /**
     * Extract a named query parameter value from a URL string literal.
     * Returns null when the parameter is absent or not a plain, unquoted string.
     */
    private function extractQueryParam(string $url, string $param): ?string
    {
        if (preg_match('/[?&]' . preg_quote($param, '/') . '=([^&\s\'"]+)/', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Returns true when the file path has a JavaScript or TypeScript extension.
     */
    private function isJavaScriptFile(?string $file): bool
    {
        if ($file === null || $file === '') {
            return false;
        }
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        return in_array($ext, ['js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs'], true);
    }
}
