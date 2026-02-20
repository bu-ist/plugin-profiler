<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\Node as GraphNode;

class BlockJsonVisitor
{
    public function __construct(
        private readonly EntityCollection $collection,
    ) {}

    /**
     * Parse a block.json file and add the block node plus relationship edges to the collection.
     */
    public function parse(string $jsonPath): void
    {
        $contents = @file_get_contents($jsonPath);
        if ($contents === false) {
            fwrite(STDERR, "Warning: Could not read block.json: $jsonPath\n");

            return;
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['name'])) {
            fwrite(STDERR, "Warning: Invalid or missing 'name' in block.json: $jsonPath\n");

            return;
        }

        $blockName = $data['name'];
        $nodeId    = 'block_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $blockName);
        $title     = $data['title'] ?? $blockName;
        $blockDir  = dirname($jsonPath);

        $metadata = [
            'block_name'       => $blockName,
            'block_category'   => $data['category'] ?? null,
            'block_attributes' => $data['attributes'] ?? null,
            'render_template'  => $data['render'] ?? null,
            'js_assets'        => $this->collectAssets($data),
            'namespace'        => explode('/', $blockName)[0] ?? null,
        ];

        $blockNode = GraphNode::make(
            id: $nodeId,
            label: $title,
            type: 'gutenberg_block',
            file: $jsonPath,
            line: 0,
            metadata: $metadata,
            docblock: $data['description'] ?? null,
        );
        $this->collection->addNode($blockNode);

        // Edge: renders_block → PHP render template
        if (isset($data['render'])) {
            $renderPath   = $this->resolveAssetPath($data['render'], $blockDir);
            $renderNodeId = 'file_' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $renderPath);

            $this->collection->addNode(GraphNode::make(
                id: $renderNodeId,
                label: basename($renderPath),
                type: 'file',
                file: $renderPath,
                line: 0,
            ));

            $this->collection->addEdge(
                Edge::make($nodeId, $renderNodeId, 'renders_block', 'renders')
            );
        }

        // Edge: enqueues_script → JS asset files
        $scriptKeys = ['editorScript', 'script', 'viewScript'];
        foreach ($scriptKeys as $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $assetPath   = $this->resolveAssetPath($data[$key], $blockDir);
            $assetNodeId = 'file_' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $assetPath);

            $this->collection->addNode(GraphNode::make(
                id: $assetNodeId,
                label: basename($assetPath),
                type: 'file',
                file: $assetPath,
                line: 0,
            ));

            $this->collection->addEdge(
                Edge::make($nodeId, $assetNodeId, 'enqueues_script', 'enqueues')
            );
        }
    }

    /**
     * Collect all JS asset references from block.json data.
     *
     * @return array<string>
     */
    private function collectAssets(array $data): array
    {
        $assets = [];
        foreach (['editorScript', 'script', 'viewScript', 'editorStyle', 'style'] as $key) {
            if (isset($data[$key])) {
                $assets[$key] = $data[$key];
            }
        }

        return $assets;
    }

    /**
     * Resolve a block.json asset reference (e.g. "file:./src/index.js") to a path.
     * Returns the resolved path relative to the block directory.
     */
    private function resolveAssetPath(string $assetRef, string $blockDir): string
    {
        // "file:./src/index.js" → "/plugin/blocks/my-block/src/index.js"
        if (str_starts_with($assetRef, 'file:')) {
            $relative = substr($assetRef, strlen('file:'));
            $resolved = realpath($blockDir . '/' . ltrim($relative, './'));

            return $resolved ?: $blockDir . '/' . ltrim($relative, './');
        }

        // Handle names or other schemes — return as-is
        return $assetRef;
    }
}
