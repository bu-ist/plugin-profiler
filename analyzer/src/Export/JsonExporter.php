<?php

declare(strict_types=1);

namespace PluginProfiler\Export;

use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;

class JsonExporter
{
    /**
     * Export the graph to a Cytoscape.js-compatible JSON file.
     */
    public function export(Graph $graph, string $outputPath): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

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
            ],
            'nodes' => array_map(fn (Node $n) => ['data' => $this->serializeNode($n)], $graph->nodes),
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
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        file_put_contents($outputPath, $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNode(Node $node): array
    {
        return [
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
    }

    /**
     * Ensure all expected metadata keys are present (even if null) for a consistent schema.
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
