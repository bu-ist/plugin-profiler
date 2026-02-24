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
     * Circular dependency cycles detected by CyclicDependencyDetector.
     * Each cycle is an array of node IDs forming a loop: ['A', 'B', 'C', 'A'].
     *
     * @var array<int, array<string>>
     */
    public array $cycles = [];

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
