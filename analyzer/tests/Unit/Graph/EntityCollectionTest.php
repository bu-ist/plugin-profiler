<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\Node;

class EntityCollectionTest extends TestCase
{
    private EntityCollection $collection;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
    }

    public function testAddNode_AddsNodeById(): void
    {
        $node = Node::make('class_Foo', 'Foo', 'class', 'foo.php');
        $this->collection->addNode($node);

        $this->assertTrue($this->collection->hasNode('class_Foo'));
        $this->assertSame($node, $this->collection->getNode('class_Foo'));
    }

    public function testAddNode_FirstWriteWins_DoesNotOverwrite(): void
    {
        $first  = Node::make('class_Foo', 'Foo', 'class', 'foo.php', 1);
        $second = Node::make('class_Foo', 'Foo Updated', 'class', 'foo.php', 2);

        $this->collection->addNode($first);
        $this->collection->addNode($second);

        $retrieved = $this->collection->getNode('class_Foo');
        $this->assertSame('Foo', $retrieved?->label, 'First node should not be overwritten');
    }

    public function testGetNode_WhenNotPresent_ReturnsNull(): void
    {
        $this->assertNull($this->collection->getNode('nonexistent'));
    }

    public function testHasNode_WhenNotPresent_ReturnsFalse(): void
    {
        $this->assertFalse($this->collection->hasNode('nonexistent'));
    }

    public function testGetAllNodes_ReturnsKeyedArray(): void
    {
        $node = Node::make('class_Foo', 'Foo', 'class', 'foo.php');
        $this->collection->addNode($node);

        $nodes = $this->collection->getAllNodes();
        $this->assertArrayHasKey('class_Foo', $nodes);
    }

    public function testAddEdge_DeduplicatesBySameId(): void
    {
        $edge1 = Edge::make('class_Foo', 'class_Bar', 'extends', 'extends');
        $edge2 = Edge::make('class_Foo', 'class_Bar', 'extends', 'extends');

        $this->collection->addEdge($edge1);
        $this->collection->addEdge($edge2);

        $this->assertCount(1, $this->collection->getAllEdges());
    }

    public function testAddEdge_DifferentEdges_AreBothStored(): void
    {
        $edge1 = Edge::make('class_Foo', 'class_Bar', 'extends', 'extends');
        $edge2 = Edge::make('class_Foo', 'interface_Baz', 'implements', 'implements');

        $this->collection->addEdge($edge1);
        $this->collection->addEdge($edge2);

        $this->assertCount(2, $this->collection->getAllEdges());
    }

    public function testSetAndGetCurrentFile(): void
    {
        $this->collection->setCurrentFile('/path/to/file.php');

        $this->assertSame('/path/to/file.php', $this->collection->getCurrentFile());
    }

    public function testSetAndGetCurrentSource(): void
    {
        $this->collection->setCurrentSource('<?php echo "hello";');

        $this->assertSame('<?php echo "hello";', $this->collection->getCurrentSource());
    }
}
