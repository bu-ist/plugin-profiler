<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

/**
 * Detects circular dependencies in the graph using DFS cycle detection.
 *
 * Scans structural edge types (extends, implements, uses_trait, calls,
 * includes, instantiates) to find cycles. Inheritance cycles
 * (extends/implements/uses_trait) are distinguished from call cycles.
 */
class CyclicDependencyDetector
{
    /**
     * Structural edge types that represent dependency relationships.
     * A cycle along these edges indicates a circular dependency.
     */
    private const STRUCTURAL_EDGES = [
        'extends',
        'implements',
        'uses_trait',
        'calls',
        'includes',
        'instantiates',
    ];

    /**
     * Detect all cycles in the graph.
     *
     * Returns an array of cycles, each represented as an array of node IDs
     * forming the loop: ['nodeA', 'nodeB', 'nodeC', 'nodeA'].
     *
     * @return array<int, array<string>>
     */
    public function detect(Graph $graph): array
    {
        // Build adjacency list from structural edges only
        $adjacency = [];
        foreach ($graph->edges as $edge) {
            if (!in_array($edge->type, self::STRUCTURAL_EDGES, true)) {
                continue;
            }
            $adjacency[$edge->source][] = [
                'target' => $edge->target,
                'type'   => $edge->type,
            ];
        }

        // DFS with WHITE (0) / GRAY (1) / BLACK (2) coloring
        $color   = [];
        $parent  = [];
        $cycles  = [];
        $seen    = []; // Deduplicate cycles by canonical form

        foreach (array_keys($adjacency) as $nodeId) {
            if (!isset($color[$nodeId])) {
                $color[$nodeId] = 0;
            }
        }

        // Also ensure all target nodes exist in color map
        foreach ($adjacency as $neighbors) {
            foreach ($neighbors as $neighbor) {
                if (!isset($color[$neighbor['target']])) {
                    $color[$neighbor['target']] = 0;
                }
            }
        }

        foreach (array_keys($color) as $nodeId) {
            if ($color[$nodeId] === 0) {
                $this->dfs($nodeId, $adjacency, $color, $parent, $cycles, $seen);
            }
        }

        return $cycles;
    }

    /**
     * @param array<string, array<array{target: string, type: string}>> $adjacency
     * @param array<string, int>    $color  0=white, 1=gray, 2=black
     * @param array<string, string> $parent Maps node → predecessor in DFS tree
     * @param array<int, array<string>> $cycles  Collected cycles
     * @param array<string, true>   $seen   Canonical cycle keys already recorded
     */
    private function dfs(
        string $nodeId,
        array &$adjacency,
        array &$color,
        array &$parent,
        array &$cycles,
        array &$seen,
    ): void {
        $color[$nodeId] = 1; // GRAY — in current DFS path

        foreach ($adjacency[$nodeId] ?? [] as $neighbor) {
            $target = $neighbor['target'];

            if (!isset($color[$target])) {
                $color[$target] = 0;
            }

            if ($color[$target] === 0) {
                // WHITE — unvisited, recurse
                $parent[$target] = $nodeId;
                $this->dfs($target, $adjacency, $color, $parent, $cycles, $seen);
            } elseif ($color[$target] === 1) {
                // GRAY — back edge: cycle detected
                $cycle = $this->extractCycle($target, $nodeId, $parent);
                if ($cycle !== null) {
                    $canonical = $this->canonicalize($cycle);
                    if (!isset($seen[$canonical])) {
                        $seen[$canonical] = true;
                        $cycles[]         = $cycle;
                    }
                }
            }
            // BLACK (2) — already fully explored, skip
        }

        $color[$nodeId] = 2; // BLACK — done
    }

    /**
     * Extract the cycle path by walking the parent chain from $cycleStart
     * back to $cycleStart, starting from $currentNode.
     *
     * @return array<string>|null  Cycle path ending with the start node repeated, or null
     */
    private function extractCycle(string $cycleStart, string $currentNode, array &$parent): ?array
    {
        $path = [$currentNode];
        $node = $currentNode;

        // Walk backwards through parent chain until we reach cycleStart
        $limit = 1000; // Safety limit
        while ($node !== $cycleStart && $limit-- > 0) {
            if (!isset($parent[$node])) {
                return null; // Broken chain
            }
            $node   = $parent[$node];
            $path[] = $node;
        }

        if ($node !== $cycleStart) {
            return null;
        }

        // Reverse so cycle reads forward: [start, ..., end, start]
        $path = array_reverse($path);
        $path[] = $cycleStart; // Close the loop

        return $path;
    }

    /**
     * Produce a canonical string for a cycle so we can deduplicate.
     * Rotate the cycle to start with the lexicographically smallest node.
     *
     * @param array<string> $cycle
     */
    private function canonicalize(array $cycle): string
    {
        // Remove the closing duplicate
        $ring = array_slice($cycle, 0, -1);
        if (empty($ring)) {
            return '';
        }

        // Find the smallest element and rotate
        $minIdx = 0;
        for ($i = 1, $len = count($ring); $i < $len; $i++) {
            if ($ring[$i] < $ring[$minIdx]) {
                $minIdx = $i;
            }
        }

        $rotated = array_merge(
            array_slice($ring, $minIdx),
            array_slice($ring, 0, $minIdx),
        );

        return implode(' → ', $rotated);
    }
}
