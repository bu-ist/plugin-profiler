<?php

declare(strict_types=1);

namespace Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\CyclicDependencyDetector;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;
use PluginProfiler\Graph\PluginMetadata;

class CyclicDependencyDetectorTest extends TestCase
{
    private CyclicDependencyDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new CyclicDependencyDetector();
    }

    public function testNoCyclesInAcyclicGraph(): void
    {
        $graph = $this->makeGraph(
            [
                $this->node('class_A'),
                $this->node('class_B'),
                $this->node('class_C'),
            ],
            [
                Edge::make('class_A', 'class_B', 'extends', 'extends'),
                Edge::make('class_B', 'class_C', 'extends', 'extends'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        $this->assertEmpty($cycles);
    }

    public function testDetectsSimpleTwoNodeCycle(): void
    {
        $graph = $this->makeGraph(
            [
                $this->node('class_A'),
                $this->node('class_B'),
            ],
            [
                Edge::make('class_A', 'class_B', 'calls', 'calls'),
                Edge::make('class_B', 'class_A', 'calls', 'calls'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        // Cycle should contain both nodes and close the loop
        $this->assertCount(3, $cycles[0]); // [A, B, A] or [B, A, B]
        $this->assertEquals($cycles[0][0], $cycles[0][count($cycles[0]) - 1]);
    }

    public function testDetectsThreeNodeCycle(): void
    {
        $graph = $this->makeGraph(
            [
                $this->node('class_A'),
                $this->node('class_B'),
                $this->node('class_C'),
            ],
            [
                Edge::make('class_A', 'class_B', 'calls', 'calls'),
                Edge::make('class_B', 'class_C', 'calls', 'calls'),
                Edge::make('class_C', 'class_A', 'calls', 'calls'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
        $this->assertCount(4, $cycles[0]); // [A, B, C, A]
        $this->assertEquals($cycles[0][0], $cycles[0][count($cycles[0]) - 1]);
    }

    public function testIgnoresNonStructuralEdges(): void
    {
        // registers_hook is not a structural edge, so no cycle should be detected
        $graph = $this->makeGraph(
            [
                $this->node('func_A'),
                $this->node('hook_B'),
            ],
            [
                Edge::make('func_A', 'hook_B', 'registers_hook', 'registers'),
                Edge::make('hook_B', 'func_A', 'triggers_hook', 'triggers'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        $this->assertEmpty($cycles);
    }

    public function testDetectsInheritanceCycle(): void
    {
        $graph = $this->makeGraph(
            [
                $this->node('class_A'),
                $this->node('class_B'),
            ],
            [
                Edge::make('class_A', 'class_B', 'extends', 'extends'),
                Edge::make('class_B', 'class_A', 'extends', 'extends'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
    }

    public function testDetectsMultipleIndependentCycles(): void
    {
        $graph = $this->makeGraph(
            [
                $this->node('class_A'),
                $this->node('class_B'),
                $this->node('class_C'),
                $this->node('class_D'),
            ],
            [
                // Cycle 1: A → B → A
                Edge::make('class_A', 'class_B', 'calls', 'calls'),
                Edge::make('class_B', 'class_A', 'calls', 'calls'),
                // Cycle 2: C → D → C
                Edge::make('class_C', 'class_D', 'calls', 'calls'),
                Edge::make('class_D', 'class_C', 'calls', 'calls'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        $this->assertCount(2, $cycles);
    }

    public function testDeduplicatesCycles(): void
    {
        // Even with multiple back-edges, a cycle between A-B-C should appear once
        $graph = $this->makeGraph(
            [
                $this->node('class_A'),
                $this->node('class_B'),
                $this->node('class_C'),
            ],
            [
                Edge::make('class_A', 'class_B', 'calls', 'calls'),
                Edge::make('class_B', 'class_C', 'calls', 'calls'),
                Edge::make('class_C', 'class_A', 'calls', 'calls'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        // Should only have one unique cycle
        $this->assertCount(1, $cycles);
    }

    public function testEmptyGraphNoCycles(): void
    {
        $graph = $this->makeGraph([], []);

        $cycles = $this->detector->detect($graph);

        $this->assertEmpty($cycles);
    }

    public function testSelfLoopDetected(): void
    {
        $graph = $this->makeGraph(
            [
                $this->node('func_A'),
            ],
            [
                Edge::make('func_A', 'func_A', 'calls', 'calls'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        // Self-loop is a cycle of length 1 (node → itself)
        $this->assertNotEmpty($cycles);
    }

    public function testIncludesCycleDetected(): void
    {
        $graph = $this->makeGraph(
            [
                $this->node('file_a_php'),
                $this->node('file_b_php'),
            ],
            [
                Edge::make('file_a_php', 'file_b_php', 'includes', 'includes'),
                Edge::make('file_b_php', 'file_a_php', 'includes', 'includes'),
            ],
        );

        $cycles = $this->detector->detect($graph);

        $this->assertCount(1, $cycles);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function node(string $id): Node
    {
        return Node::make(
            id: $id,
            label: $id,
            type: 'class',
            file: '/test.php',
            line: 1,
        );
    }

    /**
     * @param array<Node> $nodes
     * @param array<Edge> $edges
     */
    private function makeGraph(array $nodes, array $edges): Graph
    {
        return new Graph(
            nodes: $nodes,
            edges: $edges,
            plugin: new PluginMetadata(
                name: 'Test',
                version: '1.0.0',
                description: '',
                mainFile: 'test.php',
                totalFiles: 1,
                totalEntities: count($nodes),
                analyzedAt: new \DateTimeImmutable(),
            ),
        );
    }
}
