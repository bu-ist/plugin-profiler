<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\LLM;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;
use PluginProfiler\Graph\PluginMetadata;
use PluginProfiler\LLM\DescriptionGenerator;
use PluginProfiler\LLM\LLMClientInterface;

class DescriptionGeneratorTest extends TestCase
{
    private function buildGraph(array $nodes = [], array $edges = []): Graph
    {
        return new Graph(
            nodes: $nodes,
            edges: $edges,
            plugin: new PluginMetadata(
                name: 'Test',
                version: '1.0',
                description: '',
                mainFile: 'test.php',
                totalFiles: 1,
                totalEntities: count($nodes),
                analyzedAt: new DateTimeImmutable(),
            ),
        );
    }

    public function testGenerate_AttachesDescriptionsToMatchingNodes(): void
    {
        $node  = Node::make(id: 'class_Foo', label: 'Foo', type: 'class', file: 'foo.php');
        $graph = $this->buildGraph([$node]);

        $mockClient = $this->createMock(LLMClientInterface::class);
        $mockClient->expects($this->once())
            ->method('generateDescriptions')
            ->willReturn(['class_Foo' => 'Foo is a sample class.']);

        $generator = new DescriptionGenerator($mockClient);
        $generator->generate($graph);

        $this->assertSame('Foo is a sample class.', $node->description);
    }

    public function testGenerate_SkipsNodesWithNoDescription(): void
    {
        $node1 = Node::make(id: 'class_Foo', label: 'Foo', type: 'class', file: 'foo.php');
        $node2 = Node::make(id: 'class_Bar', label: 'Bar', type: 'class', file: 'bar.php');
        $graph = $this->buildGraph([$node1, $node2]);

        $mockClient = $this->createMock(LLMClientInterface::class);
        $mockClient->method('generateDescriptions')
            ->willReturn(['class_Foo' => 'Foo description.']);

        $generator = new DescriptionGenerator($mockClient);
        $generator->generate($graph);

        $this->assertSame('Foo description.', $node1->description);
        $this->assertNull($node2->description);
    }

    public function testGenerate_WithClientException_ContinuesGracefully(): void
    {
        $node  = Node::make(id: 'class_Foo', label: 'Foo', type: 'class', file: 'foo.php');
        $graph = $this->buildGraph([$node]);

        $mockClient = $this->createMock(LLMClientInterface::class);
        $mockClient->method('generateDescriptions')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $generator = new DescriptionGenerator($mockClient);
        $generator->generate($graph);

        $this->assertNull($node->description);
    }

    public function testGenerate_BatchesByConfiguredSize(): void
    {
        $nodes = [];
        for ($i = 0; $i < 5; $i++) {
            $nodes[] = Node::make(id: "class_N$i", label: "N$i", type: 'class', file: 'f.php');
        }
        $graph = $this->buildGraph($nodes);

        $mockClient = $this->createMock(LLMClientInterface::class);
        // With batch size 3 and 5 nodes: expect 2 calls
        $mockClient->expects($this->exactly(2))
            ->method('generateDescriptions')
            ->willReturn([]);

        $generator = new DescriptionGenerator($mockClient, batchSize: 3);
        $generator->generate($graph);
    }

    public function testGenerate_EmptyGraph_MakesNoCalls(): void
    {
        $graph = $this->buildGraph([]);

        $mockClient = $this->createMock(LLMClientInterface::class);
        $mockClient->expects($this->never())->method('generateDescriptions');

        $generator = new DescriptionGenerator($mockClient);
        $generator->generate($graph);
    }

    public function testGenerate_EntityPayload_IncludesId(): void
    {
        $node  = Node::make(id: 'hook_action_init', label: 'init', type: 'hook', file: 'foo.php');
        $graph = $this->buildGraph([$node]);

        $mockClient = $this->createMock(LLMClientInterface::class);
        $mockClient->expects($this->once())
            ->method('generateDescriptions')
            ->with($this->callback(function ($batch) {
                return isset($batch[0]['id']) && $batch[0]['id'] === 'hook_action_init';
            }))
            ->willReturn([]);

        $generator = new DescriptionGenerator($mockClient);
        $generator->generate($graph);
    }

    public function testGenerate_EntityPayload_IncludesConnectionsFromEdges(): void
    {
        $nodeA = Node::make(id: 'class_A', label: 'A', type: 'class', file: 'a.php');
        $nodeB = Node::make(id: 'class_B', label: 'B', type: 'class', file: 'b.php');
        $edge  = new Edge(id: 'e_0', source: 'class_A', target: 'class_B', type: 'extends', label: 'extends');

        $graph = $this->buildGraph([$nodeA, $nodeB], [$edge]);

        $mockClient = $this->createMock(LLMClientInterface::class);
        $mockClient->expects($this->once())
            ->method('generateDescriptions')
            ->with($this->callback(function ($batch) {
                // nodeA should have connections to class_B
                $payloadA = collect_by_id($batch, 'class_A');
                return $payloadA !== null && !empty($payloadA['connections']);
            }))
            ->willReturn([]);

        $generator = new DescriptionGenerator($mockClient);
        $generator->generate($graph);
    }
}

function collect_by_id(array $batch, string $id): ?array
{
    foreach ($batch as $item) {
        if (($item['id'] ?? null) === $id) return $item;
    }
    return null;
}
