<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Graph;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\GraphBuilder;
use PluginProfiler\Graph\Node;
use PluginProfiler\Graph\PluginMetadata;

class GraphBuilderTest extends TestCase
{
    private GraphBuilder $builder;
    private PluginMetadata $meta;

    protected function setUp(): void
    {
        $this->builder = new GraphBuilder();
        $this->meta    = new PluginMetadata(
            name: 'Test Plugin',
            version: '1.0.0',
            description: '',
            mainFile: 'test.php',
            totalFiles: 1,
            totalEntities: 0,
            analyzedAt: new DateTimeImmutable(),
        );
    }

    public function testBuild_WithValidEdge_PreservesEdge(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'node_a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addNode(Node::make(id: 'node_b', label: 'B', type: 'class', file: 'b.php'));
        $collection->addEdge(Edge::make('node_a', 'node_b', 'extends', 'extends'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(1, $graph->edges);
    }

    public function testBuild_WithOrphanEdgeSourceMissing_DropsEdge(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'node_b', label: 'B', type: 'class', file: 'b.php'));
        $collection->addEdge(Edge::make('nonexistent_source', 'node_b', 'extends', 'extends'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(0, $graph->edges);
    }

    public function testBuild_WithOrphanEdgeTargetMissing_DropsEdge(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'node_a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addEdge(Edge::make('node_a', 'nonexistent_target', 'extends', 'extends'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(0, $graph->edges);
    }

    public function testBuild_EdgeIds_AreSequentialStrings(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addNode(Node::make(id: 'b', label: 'B', type: 'class', file: 'b.php'));
        $collection->addNode(Node::make(id: 'c', label: 'C', type: 'class', file: 'c.php'));
        $collection->addEdge(Edge::make('a', 'b', 'extends', 'extends'));
        $collection->addEdge(Edge::make('b', 'c', 'implements', 'implements'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertSame('e_0', $graph->edges[0]->id);
        $this->assertSame('e_1', $graph->edges[1]->id);
    }

    public function testBuild_AllNodes_ArePreserved(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addNode(Node::make(id: 'b', label: 'B', type: 'function', file: 'b.php'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(2, $graph->nodes);
    }

    public function testBuild_PluginMetadata_IsPassedThrough(): void
    {
        $collection = new EntityCollection();
        $graph      = $this->builder->build($collection, $this->meta);

        $this->assertSame('Test Plugin', $graph->plugin->name);
        $this->assertSame('1.0.0', $graph->plugin->version);
    }

    public function testBuild_WithMixedEdges_OnlyValidEdgesKept(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addNode(Node::make(id: 'b', label: 'B', type: 'class', file: 'b.php'));

        $collection->addEdge(Edge::make('a', 'b', 'extends', 'extends'));          // valid
        $collection->addEdge(Edge::make('a', 'missing', 'extends', 'extends'));    // invalid target
        $collection->addEdge(Edge::make('missing', 'b', 'extends', 'extends'));    // invalid source

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(1, $graph->edges);
        $this->assertSame('a', $graph->edges[0]->source);
        $this->assertSame('b', $graph->edges[0]->target);
    }

    public function testBuild_EmptyCollection_ReturnsEmptyGraph(): void
    {
        $collection = new EntityCollection();
        $graph      = $this->builder->build($collection, $this->meta);

        $this->assertCount(0, $graph->nodes);
        $this->assertCount(0, $graph->edges);
    }
}
