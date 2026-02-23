<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class Graph
{
    /**
     * AI-generated high-level summary of the plugin architecture.
     * Populated by DescriptionGenerator::generateSummary() when LLM is enabled.
     * null when no LLM is configured or the summary call failed.
     */
    public ?string $aiSummary = null;

    /**
     * @param array<Node> $nodes
     * @param array<Edge> $edges
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $edges,
        public readonly PluginMetadata $plugin,
    ) {
    }
}
