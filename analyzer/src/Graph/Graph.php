<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class Graph
{
    /**
     * @param array<Node> $nodes
     * @param array<Edge> $edges
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $edges,
        public readonly PluginMetadata $plugin,
    ) {}
}
