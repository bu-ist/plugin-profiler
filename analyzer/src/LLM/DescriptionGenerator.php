<?php

declare(strict_types=1);

namespace PluginProfiler\LLM;

use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;

class DescriptionGenerator
{
    public function __construct(
        private readonly LLMClientInterface $client,
        private readonly int $batchSize = 25,
    ) {
    }

    /**
     * Attach AI-generated descriptions to all nodes in the graph (in-place).
     *
     * @param callable(int $done, int $total): void|null $onProgress
     */
    public function generate(Graph $graph, ?callable $onProgress = null): void
    {
        $batches = array_chunk($graph->nodes, $this->batchSize);
        $total   = count($graph->nodes);
        $done    = 0;

        foreach ($batches as $batch) {
            $entityBatch = array_map(fn (Node $n) => $this->buildEntityPayload($n, $graph), $batch);

            try {
                $descriptions = $this->client->generateDescriptions($entityBatch);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Warning: LLM batch failed: {$e->getMessage()}\n");
                $done += count($batch);
                if ($onProgress !== null) {
                    ($onProgress)($done, $total);
                }
                continue;
            }

            foreach ($batch as $node) {
                if (isset($descriptions[$node->id])) {
                    $node->description = $descriptions[$node->id];
                }
            }

            $done += count($batch);
            if ($onProgress !== null) {
                ($onProgress)($done, $total);
            }
        }
    }

    /**
     * Generate a high-level technical summary of the plugin architecture.
     *
     * Sends one final LLM request after all entity descriptions are generated.
     * Uses a compact manifest of entity types, counts, and a sample of
     * existing descriptions as context.
     *
     * Returns the summary string, or null if the LLM call fails.
     */
    public function generateSummary(Graph $graph): ?string
    {
        $plugin = $graph->plugin;

        // Build a compact manifest of entity types and counts
        $typeCounts = [];
        foreach ($graph->nodes as $node) {
            $typeCounts[$node->type] = ($typeCounts[$node->type] ?? 0) + 1;
        }
        arsort($typeCounts);

        // Include up to 20 representative descriptions (prefer classes/functions)
        $samples  = [];
        $priority = ['class', 'interface', 'function', 'rest_endpoint', 'ajax_handler', 'hook', 'data_source'];
        foreach ($priority as $type) {
            foreach ($graph->nodes as $node) {
                if ($node->type === $type && !empty($node->description)) {
                    $samples[] = "[{$node->type}] {$node->label}: {$node->description}";
                    if (count($samples) >= 20) {
                        break 2;
                    }
                }
            }
        }

        $contextJson = json_encode([
            'plugin_name'         => $plugin->name,
            'plugin_version'      => $plugin->version,
            'entity_counts'       => $typeCounts,
            'total_nodes'         => count($graph->nodes),
            'total_edges'         => count($graph->edges),
            'sample_descriptions' => $samples,
        ], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are a WordPress plugin architecture expert. Based on the JSON data below,
write a 3-5 paragraph technical overview of this plugin for a senior WordPress developer.
Cover: purpose, main architectural components, WordPress integrations (hooks, REST endpoints,
data storage), and any notable patterns or design decisions. Plain prose only — no JSON,
no bullet lists, no headings.

{$contextJson}
PROMPT;

        try {
            $text = $this->client->generateText($prompt);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Warning: Plugin summary generation failed: {$e->getMessage()}\n");

            return null;
        }

        return ($text !== null && strlen($text) > 50) ? trim($text) : null;
    }

    /**
     * Build a metadata payload for a single entity suitable for LLM prompting.
     *
     * @return array<string, mixed>
     */
    private function buildEntityPayload(Node $node, Graph $graph): array
    {
        $payload = [
            'id'    => $node->id,
            'type'  => $node->type,
            'label' => $node->label,
        ];

        // Add relevant metadata fields
        $meta = $node->metadata;
        if (!empty($meta['namespace'])) {
            $payload['namespace']  = $meta['namespace'];
        }
        if (!empty($meta['extends'])) {
            $payload['extends']    = $meta['extends'];
        }
        if (!empty($meta['implements'])) {
            $payload['implements'] = $meta['implements'];
        }
        if (!empty($meta['hook_name'])) {
            $payload['hook_name']  = $meta['hook_name'];
        }
        if (!empty($meta['http_method'])) {
            $payload['http_method'] = $meta['http_method'];
        }
        if (!empty($meta['route'])) {
            $payload['route']      = $meta['route'];
        }
        if (!empty($meta['operation'])) {
            $payload['operation']  = $meta['operation'];
        }
        if (!empty($meta['key'])) {
            $payload['key']        = $meta['key'];
        }
        if (!empty($meta['block_name'])) {
            $payload['block_name'] = $meta['block_name'];
        }

        // Add connected node labels as context
        $connectedIds = [];
        foreach ($graph->edges as $edge) {
            if ($edge->source === $node->id) {
                $connectedIds[] = $edge->target . ' (' . $edge->type . ')';
            }
        }
        if (!empty($connectedIds)) {
            $payload['connections'] = array_slice($connectedIds, 0, 10);
        }

        // Include first 10 lines of source as context
        if ($node->sourcePreview !== null) {
            $lines = explode("\n", $node->sourcePreview);
            $payload['code_snippet'] = implode("\n", array_slice($lines, 0, 10));
        }

        return $payload;
    }
}
