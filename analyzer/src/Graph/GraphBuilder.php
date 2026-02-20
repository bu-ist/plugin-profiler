<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class GraphBuilder
{
    /**
     * Build a validated Graph from the EntityCollection.
     *
     * - Drops edges whose source or target node ID does not exist.
     * - Reassigns edge IDs sequentially ("e_0", "e_1", ...).
     */
    public function build(EntityCollection $collection, PluginMetadata $meta): Graph
    {
        $nodes   = $collection->getAllNodes();
        $nodeIds = array_keys($nodes);
        $nodeSet = array_flip($nodeIds);

        $validEdges    = [];
        $edgeSequence  = 0;

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

        return new Graph(
            nodes: array_values($nodes),
            edges: $validEdges,
            plugin: $meta,
        );
    }
}
