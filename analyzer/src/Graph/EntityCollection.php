<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class EntityCollection
{
    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<string, Edge> */
    private array $edges = [];

    private string $currentFile = '';
    private string $currentSource = '';

    public function addNode(Node $node): void
    {
        // First-write-wins: do not overwrite an existing node with the same ID
        if (!isset($this->nodes[$node->id])) {
            $this->nodes[$node->id] = $node;
        }
    }

    public function addEdge(Edge $edge): void
    {
        // Deduplicate edges by their deterministic ID
        $this->edges[$edge->id] = $edge;
    }

    public function getNode(string $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** @return array<string, Node> */
    public function getAllNodes(): array
    {
        return $this->nodes;
    }

    /** @return array<Edge> */
    public function getAllEdges(): array
    {
        return array_values($this->edges);
    }

    public function setCurrentFile(string $filePath): void
    {
        $this->currentFile = $filePath;
    }

    public function getCurrentFile(): string
    {
        return $this->currentFile;
    }

    public function setCurrentSource(string $source): void
    {
        $this->currentSource = $source;
    }

    public function getCurrentSource(): string
    {
        return $this->currentSource;
    }
}
