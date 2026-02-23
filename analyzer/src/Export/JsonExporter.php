<?php

declare(strict_types=1);

namespace PluginProfiler\Export;

use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;

/**
 * Serialises a Graph to a Cytoscape.js-compatible JSON file.
 *
 * The output schema follows graph-schema.md. Every node `data` object carries
 * all expected metadata keys (even when null) so the frontend can access them
 * without defensive `?.` checks.
 *
 * Compound (parent) nodes are generated automatically from PHP namespaces and
 * JS directory paths. Nodes with two or more siblings in the same namespace /
 * directory receive a `parent` key pointing to the compound node. This lets
 * Cytoscape's expand-collapse extension group and collapse related entities.
 */
class JsonExporter
{
    /**
     * PHP node types that participate in namespace grouping.
     * Methods are intentionally excluded — they are visually attached to their
     * class via `has_method` edges, so double-grouping them adds clutter.
     */
    private const PHP_GROUPED_TYPES = ['class', 'interface', 'trait', 'function'];

    /**
     * JS node types that participate in directory grouping.
     */
    private const JS_GROUPED_TYPES = [
        'js_function', 'js_class', 'react_component', 'react_hook',
        'fetch_call', 'axios_call', 'js_hook', 'js_api_call',
    ];

    /**
     * Minimum number of children required to emit a compound parent node.
     * Single-child groups add visual noise without organisational benefit.
     */
    private const MIN_GROUP_SIZE = 2;

    /**
     * Export the graph to a Cytoscape.js-compatible JSON file.
     */
    public function export(Graph $graph, string $outputPath): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        [$compoundNodes, $parentMap] = $this->buildGroupNodes($graph);

        $data = [
            'plugin' => [
                'name'             => $graph->plugin->name,
                'version'          => $graph->plugin->version,
                'description'      => $graph->plugin->description,
                'main_file'        => $graph->plugin->mainFile,
                'total_files'      => $graph->plugin->totalFiles,
                'total_entities'   => $graph->plugin->totalEntities,
                'analyzed_at'      => $graph->plugin->analyzedAt->format(\DateTimeInterface::ATOM),
                'analyzer_version' => $graph->plugin->analyzerVersion,
                'host_path'        => $graph->plugin->hostPath,
                'php_files'        => $graph->plugin->phpFiles,
                'js_files'         => $graph->plugin->jsFiles,
                'summary'          => $graph->aiSummary,
            ],
            // Compound nodes come first so Cytoscape can resolve parent references
            'nodes' => [
                ...$compoundNodes,
                ...array_map(
                    fn (Node $n) => ['data' => $this->serializeNode($n, $parentMap[$n->id] ?? null)],
                    $graph->nodes,
                ),
            ],
            'edges' => array_map(fn ($e) => ['data' => [
                'id'     => $e->id,
                'source' => $e->source,
                'target' => $e->target,
                'type'   => $e->type,
                'label'  => $e->label,
            ]], $graph->edges),
        ];

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        file_put_contents($outputPath, $json);
    }

    // ── Compound node generation ───────────────────────────────────────────

    /**
     * Build compound parent nodes for PHP namespaces and JS directories.
     *
     * Returns a tuple:
     *   [0] array of Cytoscape element arrays ready for JSON export
     *   [1] map of nodeId => parentGroupId for child assignment
     *
     * @return array{array<int,array<string,mixed>>, array<string,string>}
     */
    private function buildGroupNodes(Graph $graph): array
    {
        // Collect candidate groups: groupId => ['label' => string, 'members' => string[]]
        $groups = [];

        foreach ($graph->nodes as $node) {
            $groupId = $this->resolveGroupId($node);
            if ($groupId === null) {
                continue;
            }

            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'label'   => $this->resolveGroupLabel($node, $groupId),
                    'type'    => $this->resolveGroupType($groupId),
                    'members' => [],
                ];
            }
            $groups[$groupId]['members'][] = $node->id;
        }

        $compoundElements = [];
        $parentMap        = [];

        foreach ($groups as $groupId => $group) {
            // Only emit a compound node when it would contain multiple children
            if (count($group['members']) < self::MIN_GROUP_SIZE) {
                continue;
            }

            $compoundElements[] = [
                'data' => [
                    'id'    => $groupId,
                    'label' => $group['label'],
                    'type'  => $group['type'],
                ],
            ];

            foreach ($group['members'] as $memberId) {
                $parentMap[$memberId] = $groupId;
            }
        }

        return [$compoundElements, $parentMap];
    }

    /**
     * Return the compound group ID for a node, or null if it should not be grouped.
     */
    private function resolveGroupId(Node $node): ?string
    {
        if (in_array($node->type, self::PHP_GROUPED_TYPES, true)) {
            $ns = $node->metadata['namespace'] ?? null;
            if ($ns === null || $ns === '') {
                return null;
            }
            return 'ns_' . Node::sanitizeId($ns);
        }

        if (in_array($node->type, self::JS_GROUPED_TYPES, true)) {
            $relDir = $this->fileToRelativeDir($node->file ?? '');
            if ($relDir === null) {
                return null;
            }
            return 'dir_' . Node::sanitizeId($relDir);
        }

        return null;
    }

    /**
     * Return a human-readable label for a compound group node.
     */
    private function resolveGroupLabel(Node $node, string $groupId): string
    {
        if (in_array($node->type, self::PHP_GROUPED_TYPES, true)) {
            return $node->metadata['namespace'] ?? $groupId;
        }

        // JS: reconstruct the directory path from the sanitized ID is lossy;
        // instead derive it fresh from the file path.
        return $this->fileToRelativeDir($node->file ?? '') ?? $groupId;
    }

    /**
     * Return 'namespace' or 'dir' based on the group ID prefix.
     */
    private function resolveGroupType(string $groupId): string
    {
        return str_starts_with($groupId, 'ns_') ? 'namespace' : 'dir';
    }

    /**
     * Convert an absolute file path to a relative directory path, or null if
     * the file is at the plugin root (grouping root-level files is rarely useful).
     *
     * Inside Docker the plugin is always mounted at /plugin, so we strip that
     * prefix to get the path relative to the plugin root.
     *
     * @example "/plugin/src/components/Button.jsx" → "src/components"
     * @example "/plugin/index.js"                  → null  (root level)
     */
    private function fileToRelativeDir(string $filePath): ?string
    {
        $relative = ltrim(str_replace('/plugin', '', $filePath), '/');
        $dir      = dirname($relative);

        if ($dir === '.' || $dir === '') {
            return null;  // Root-level file — do not group
        }

        return $dir;
    }

    // ── Node serialisation ─────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function serializeNode(Node $node, ?string $parent = null): array
    {
        $data = [
            'id'             => $node->id,
            'label'          => $node->label,
            'type'           => $node->type,
            'subtype'        => $node->subtype,
            'file'           => $node->file,
            'line'           => $node->line,
            'metadata'       => $this->normalizeMetadata($node->metadata),
            'docblock'       => $node->docblock,
            'description'    => $node->description,
            'source_preview' => $node->sourcePreview !== null
                ? $this->sanitizeUtf8($node->sourcePreview)
                : null,
        ];

        // Only emit `parent` when the node belongs to a compound group;
        // omitting the key entirely is cleaner than setting it to null.
        if ($parent !== null) {
            $data['parent'] = $parent;
        }

        return $data;
    }

    /**
     * Ensure all expected metadata keys are present (even if null) for a
     * consistent schema — the frontend can access any key without null-checks.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $defaults = [
            'namespace'        => null,
            'extends'          => null,
            'implements'       => null,
            'visibility'       => null,
            'params'           => null,
            'return_type'      => null,
            'priority'         => null,
            'hook_name'        => null,
            'http_method'      => null,
            'route'            => null,
            'operation'        => null,
            'key'              => null,
            'block_name'       => null,
            'block_category'   => null,
            'block_attributes' => null,
            'render_template'  => null,
            'js_assets'        => null,
        ];

        return array_merge($defaults, $metadata);
    }

    private function sanitizeUtf8(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
