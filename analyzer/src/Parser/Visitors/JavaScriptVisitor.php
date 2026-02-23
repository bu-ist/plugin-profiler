<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\Node as GraphNode;

/**
 * Parses JS/JSX/TS/TSX files by shelling out to js-extractor.mjs (Node.js + Babel).
 * This gives full JSX/TSX/TypeScript support without any PHP-side parser limitations.
 *
 * Detected entity types:
 *   react_component  — Function/arrow component returning JSX
 *   react_hook       — useState, useEffect, useContext, custom hooks
 *   js_function      — Plain JS function declarations
 *   js_class         — Class declarations
 *   js_hook          — wp.hooks.addAction/addFilter (register), doAction/applyFilters (trigger), removeAction/removeFilter (remove)
 *   js_api_call      — WordPress apiFetch()
 *   fetch_call       — Native fetch() or jQuery $.ajax/$.get/$.post
 *   axios_call       — axios.get/post/etc.
 *   gutenberg_block  — registerBlockType()
 *   wp_store         — @wordpress/data select/dispatch/subscribe store access
 *   js_import        — Third-party package imports (for dependency edges)
 *   js_relative_import — Relative imports between plugin files → `imports` edge
 */
class JavaScriptVisitor
{
    /** Path to the Node.js extractor script */
    private string $extractorPath;

    public function __construct(
        private readonly EntityCollection $collection,
        ?string $extractorPath = null,
    ) {
        $this->extractorPath = $extractorPath ?? dirname(__DIR__, 3) . '/bin/js-extractor.mjs';
    }

    /**
     * Parse a JS/JSX/TS/TSX file and populate the entity collection.
     */
    public function parse(string $source, string $filePath): void
    {
        if (!is_readable($this->extractorPath)) {
            // Fallback: skip gracefully if extractor not found (e.g. in unit tests without node_modules)
            fwrite(STDERR, "Warning: js-extractor.mjs not found at {$this->extractorPath}, skipping JS analysis\n");
            return;
        }

        $entities = $this->runExtractor($filePath);
        if ($entities === null) {
            return;
        }

        // Ensure a file node exists for edge anchoring
        $fileNodeId = GraphNode::sanitizeId('file_' . $filePath);
        if (!$this->collection->hasNode($fileNodeId)) {
            $this->collection->addNode(GraphNode::make(
                id: $fileNodeId,
                label: basename($filePath),
                type: 'file',
                file: $filePath,
                line: 0,
            ));
        }

        foreach ($entities as $entity) {
            $this->processEntity($entity, $filePath, $fileNodeId);
        }
    }

    /**
     * Run the Node.js extractor and return the decoded entity array, or null on failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function runExtractor(string $filePath): ?array
    {
        $cmd = sprintf(
            'node %s %s 2>&1',
            escapeshellarg($this->extractorPath),
            escapeshellarg($filePath),
        );

        $output = shell_exec($cmd);
        if ($output === null || $output === '') {
            return null;
        }

        // Separate stderr warnings (lines not starting with '[') from JSON output
        $lines   = explode("\n", trim($output));
        $jsonLine = null;
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ($line !== '' && ($line[0] === '[' || $line[0] === '{')) {
                $jsonLine = $line;
                break;
            }
            if ($line !== '') {
                fwrite(STDERR, $line . "\n");
            }
        }

        if ($jsonLine === null) {
            return null;
        }

        $decoded = json_decode($jsonLine, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "Warning: Invalid JSON from js-extractor for $filePath\n");

            return null;
        }

        return $decoded;
    }

    /**
     * Map one extractor entity to a graph node + edge.
     *
     * @param array<string, mixed> $entity
     */
    private function processEntity(array $entity, string $filePath, string $fileNodeId): void
    {
        $type    = (string) ($entity['type']    ?? '');
        $subtype = (string) ($entity['subtype'] ?? '');
        $name    = (string) ($entity['name']    ?? '');
        $l       = (int)    ($entity['line']    ?? 0);
        $meta    = (array)  ($entity['meta']    ?? []);

        if ($type === '' || $name === '') {
            return;
        }

        switch ($type) {
            case 'react_component':
                $nodeId = GraphNode::sanitizeId('react_comp_' . md5($filePath) . '_' . $name);
                $this->addNode($nodeId, $name, 'react_component', $filePath, $l, $meta);
                $this->addEdge($fileNodeId, $nodeId, 'defines_component', 'defines');
                break;

            case 'react_hook':
                $nodeId = GraphNode::sanitizeId('react_hook_' . $subtype . '_' . $name . '_' . md5($filePath . $l));
                $this->addNode($nodeId, $name, 'react_hook', $filePath, $l, array_merge($meta, ['hook_kind' => $subtype]));
                $this->addEdge($fileNodeId, $nodeId, 'uses_hook', 'uses');
                break;

            case 'js_function':
                $nodeId = GraphNode::sanitizeId('js_func_' . md5($filePath) . '_' . $name);
                $this->addNode($nodeId, $name, 'js_function', $filePath, $l, $meta);
                $this->addEdge($fileNodeId, $nodeId, 'defines', 'defines');
                break;

            case 'js_class':
                $nodeId = GraphNode::sanitizeId('js_class_' . md5($filePath) . '_' . $name);
                $this->addNode($nodeId, $name, 'js_class', $filePath, $l, $meta);
                $this->addEdge($fileNodeId, $nodeId, 'defines', 'defines');
                break;

            case 'gutenberg_block':
                $nodeId = 'block_' . GraphNode::sanitizeId($name);
                $this->addNode($nodeId, $name, 'gutenberg_block', $filePath, $l, array_merge($meta, ['block_name' => $name]));
                $this->addEdge($fileNodeId, $nodeId, 'registers_block', 'registers');
                break;

            case 'js_hook':
                $hookBaseType = match ($subtype) {
                    'action', 'filter' => 'register',
                    'action_trigger', 'filter_trigger' => 'trigger',
                    'action_remove', 'filter_remove' => 'remove',
                    default => 'register',
                };
                // Normalise type for node ID: strip trigger/remove suffixes
                $hookKind = str_contains($subtype, 'action') ? 'action' : 'filter';
                $nodeId   = 'js_hook_' . $hookKind . '_' . GraphNode::sanitizeId($name);
                $this->addNode($nodeId, $name, 'js_hook', $filePath, $l, array_merge($meta, ['hook_name' => $name]), $hookKind);
                $edgeType = match ($hookBaseType) {
                    'trigger' => 'triggers_hook',
                    'remove'  => 'deregisters_hook',
                    default   => 'js_registers_hook',
                };
                $this->addEdge($fileNodeId, $nodeId, $edgeType, $hookBaseType);
                break;

            case 'wp_store':
                $storeId = GraphNode::sanitizeId('wp_store_' . ($meta['store'] ?? $name));
                $this->addNode($storeId, $name, 'wp_store', $filePath, $l, $meta, $subtype);
                $edgeType = match ($subtype) {
                    'write'     => 'writes_store',
                    'subscribe' => 'reads_store',
                    default     => 'reads_store',
                };
                $this->addEdge($fileNodeId, $storeId, $edgeType, $subtype ?: 'reads');
                break;

            case 'js_api_call':
                $method = strtoupper((string) ($meta['http_method'] ?? 'GET'));
                $path   = (string) ($meta['route'] ?? $name);
                $nodeId = 'js_api_' . GraphNode::sanitizeId($method . '_' . $path);
                $this->addNode($nodeId, $name, 'js_api_call', $filePath, $l, $meta);
                $this->addEdge($fileNodeId, $nodeId, 'js_api_call', 'calls');
                break;

            case 'fetch_call':
                $method = strtoupper((string) ($meta['http_method'] ?? 'GET'));
                $route  = (string) ($meta['route'] ?? '');
                $nodeId = GraphNode::sanitizeId('fetch_' . $method . '_' . ($route ?: md5($filePath . $l)));
                $this->addNode($nodeId, $name, 'fetch_call', $filePath, $l, $meta);
                $this->addEdge($fileNodeId, $nodeId, 'http_call', 'calls');
                break;

            case 'axios_call':
                $method = strtoupper((string) ($meta['http_method'] ?? 'GET'));
                $route  = (string) ($meta['route'] ?? '');
                $nodeId = GraphNode::sanitizeId('axios_' . $method . '_' . ($route ?: md5($filePath . $l)));
                $this->addNode($nodeId, $name, 'axios_call', $filePath, $l, $meta);
                $this->addEdge($fileNodeId, $nodeId, 'http_call', 'calls');
                break;

            case 'js_import':
                // Package imports — informational only, no visual nodes (too noisy)
                break;

            case 'js_relative_import':
                // Intra-plugin relative import: emit an `imports` edge from this file
                // to the target file. The target node may not exist yet (files are
                // processed sequentially); GraphBuilder silently drops unresolvable edges.
                $targetPath = $this->resolveRelativeImport((string) ($meta['source'] ?? $name), $filePath);
                if ($targetPath !== null) {
                    $targetNodeId = GraphNode::sanitizeId('file_' . $targetPath);
                    $this->addEdge($fileNodeId, $targetNodeId, 'imports', 'imports');
                }
                break;
        }
    }

    /**
     * Resolve a relative JS import path (e.g. './utils', '../hooks/useFoo') to an
     * absolute file path in the container, trying common JS/TS extensions.
     * Returns null when the target cannot be found on disk.
     */
    private function resolveRelativeImport(string $src, string $fromFile): ?string
    {
        $dir = dirname($fromFile);

        // Try the path as-is (already has an extension)
        $direct = realpath($dir . '/' . $src);
        if ($direct !== false) {
            return $direct;
        }

        // Try appending common JS/TS extensions
        foreach (['.js', '.jsx', '.ts', '.tsx', '.mjs', '.cjs'] as $ext) {
            $candidate = realpath($dir . '/' . $src . $ext);
            if ($candidate !== false) {
                return $candidate;
            }
        }

        // Try index files inside the named directory
        foreach (['/index.js', '/index.jsx', '/index.ts', '/index.tsx'] as $suffix) {
            $candidate = realpath($dir . '/' . $src . $suffix);
            if ($candidate !== false) {
                return $candidate;
            }
        }

        return null;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta
     */
    private function addNode(
        string $id,
        string $label,
        string $type,
        string $file,
        int $line,
        array $meta = [],
        string $subtype = '',
    ): void {
        if ($this->collection->hasNode($id)) {
            return;
        }
        $this->collection->addNode(GraphNode::make(
            id: $id,
            label: $label,
            type: $type,
            file: $file,
            line: $line,
            subtype: $subtype,
            metadata: $meta,
        ));
    }

    private function addEdge(string $source, string $target, string $type, string $label): void
    {
        $this->collection->addEdge(Edge::make($source, $target, $type, $label));
    }
}
