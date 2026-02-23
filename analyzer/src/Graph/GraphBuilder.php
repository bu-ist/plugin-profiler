<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class GraphBuilder
{
    /**
     * Directory segments (case-insensitive) that indicate bundled third-party
     * library code rather than developer-written code.
     * Nodes whose file paths contain one of these segments (as an exact path
     * component, not just a substring) are tagged `isLibrary = true` so the
     * frontend can offer a "developer code only" filter.
     */
    private const LIBRARY_SEGMENTS = [
        'lib', 'libs',
        'third-party', 'thirdparty',
        'bower_components',
        'external', 'externals',
    ];

    /**
     * Build a validated Graph from the EntityCollection.
     *
     * - Drops edges whose source or target node ID does not exist.
     * - Reassigns edge IDs sequentially ("e_0", "e_1", ...).
     * - Tags nodes with isLibrary = true when their file is in a bundled
     *   library directory (lib/, libs/, third-party/, …).
     */
    public function build(EntityCollection $collection, PluginMetadata $meta): Graph
    {
        $nodes   = $collection->getAllNodes();
        $nodeIds = array_keys($nodes);
        $nodeSet = array_flip($nodeIds);

        $validEdges   = [];
        $edgeSequence = 0;

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

        // Tag library nodes so the frontend can filter them
        foreach ($nodes as $node) {
            if ($this->isLibraryFile($node->file)) {
                $node->isLibrary = true;
            }
        }

        return new Graph(
            nodes: array_values($nodes),
            edges: $validEdges,
            plugin: $meta,
        );
    }

    /**
     * Return true when any non-filename path segment matches a known library
     * directory name (exact, case-insensitive comparison).
     */
    private function isLibraryFile(string $filePath): bool
    {
        $normalized = str_replace(['\\', DIRECTORY_SEPARATOR], '/', $filePath);
        $parts      = explode('/', $normalized);

        // Exclude the last element (filename) — only test directory segments
        $dirs = array_slice($parts, 0, count($parts) - 1);

        foreach ($dirs as $segment) {
            if (in_array(strtolower($segment), self::LIBRARY_SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }
}
