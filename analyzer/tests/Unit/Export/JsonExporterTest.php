<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Export;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Export\JsonExporter;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;
use PluginProfiler\Graph\PluginMetadata;

class JsonExporterTest extends TestCase
{
    private JsonExporter $exporter;
    private string $outputPath;

    protected function setUp(): void
    {
        $this->exporter   = new JsonExporter();
        $this->outputPath = sys_get_temp_dir() . '/plugin-profiler-test-' . uniqid() . '/graph-data.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
            @rmdir(dirname($this->outputPath));
        }
    }

    private function buildGraph(array $nodes = [], array $edges = []): Graph
    {
        return new Graph(
            nodes: $nodes,
            edges: $edges,
            plugin: new PluginMetadata(
                name: 'Test Plugin',
                version: '1.0.0',
                description: 'A test plugin.',
                mainFile: 'test.php',
                totalFiles: 5,
                totalEntities: count($nodes),
                analyzedAt: new DateTimeImmutable('2026-01-01T12:00:00Z'),
            ),
        );
    }

    public function testExport_ProducesValidJson(): void
    {
        $graph = $this->buildGraph();
        $this->exporter->export($graph, $this->outputPath);

        $this->assertFileExists($this->outputPath);
        $decoded = json_decode(file_get_contents($this->outputPath), true);
        $this->assertIsArray($decoded);
    }

    public function testExport_CreatesDirectoryIfNotExists(): void
    {
        $graph = $this->buildGraph();
        $this->exporter->export($graph, $this->outputPath);

        $this->assertDirectoryExists(dirname($this->outputPath));
    }

    public function testExport_PluginSectionHasRequiredKeys(): void
    {
        $graph   = $this->buildGraph();
        $this->exporter->export($graph, $this->outputPath);
        $decoded = json_decode(file_get_contents($this->outputPath), true);

        $plugin = $decoded['plugin'];
        $this->assertArrayHasKey('name', $plugin);
        $this->assertArrayHasKey('version', $plugin);
        $this->assertArrayHasKey('description', $plugin);
        $this->assertArrayHasKey('main_file', $plugin);
        $this->assertArrayHasKey('total_files', $plugin);
        $this->assertArrayHasKey('total_entities', $plugin);
        $this->assertArrayHasKey('analyzed_at', $plugin);
        $this->assertArrayHasKey('analyzer_version', $plugin);
    }

    public function testExport_NodeIsWrappedInDataKey(): void
    {
        $graph = $this->buildGraph([
            Node::make(id: 'class_Foo', label: 'Foo', type: 'class', file: 'foo.php'),
        ]);
        $this->exporter->export($graph, $this->outputPath);
        $decoded = json_decode(file_get_contents($this->outputPath), true);

        $this->assertArrayHasKey('data', $decoded['nodes'][0]);
    }

    public function testExport_NodeDataHasAllSchemaKeys(): void
    {
        $graph = $this->buildGraph([
            Node::make(id: 'class_Foo', label: 'Foo', type: 'class', file: 'foo.php'),
        ]);
        $this->exporter->export($graph, $this->outputPath);
        $decoded  = json_decode(file_get_contents($this->outputPath), true);
        $nodeData = $decoded['nodes'][0]['data'];

        foreach (['id', 'label', 'type', 'subtype', 'file', 'line', 'metadata', 'docblock', 'description', 'source_preview'] as $key) {
            $this->assertArrayHasKey($key, $nodeData, "Missing key: $key");
        }
    }

    public function testExport_MetadataHasAllDefaultKeys(): void
    {
        $graph = $this->buildGraph([
            Node::make(id: 'class_Foo', label: 'Foo', type: 'class', file: 'foo.php'),
        ]);
        $this->exporter->export($graph, $this->outputPath);
        $decoded  = json_decode(file_get_contents($this->outputPath), true);
        $metadata = $decoded['nodes'][0]['data']['metadata'];

        foreach (['namespace', 'extends', 'implements', 'visibility', 'params', 'return_type', 'priority', 'hook_name', 'http_method', 'route', 'operation', 'key'] as $key) {
            $this->assertArrayHasKey($key, $metadata, "Missing metadata key: $key");
        }
    }

    public function testExport_EdgeIsWrappedInDataKey(): void
    {
        $nodeA = Node::make(id: 'a', label: 'A', type: 'class', file: 'a.php');
        $nodeB = Node::make(id: 'b', label: 'B', type: 'class', file: 'b.php');
        $edge  = new Edge(id: 'e_0', source: 'a', target: 'b', type: 'extends', label: 'extends');

        $graph = $this->buildGraph([$nodeA, $nodeB], [$edge]);
        $this->exporter->export($graph, $this->outputPath);
        $decoded = json_decode(file_get_contents($this->outputPath), true);

        $this->assertArrayHasKey('data', $decoded['edges'][0]);
    }

    public function testExport_EdgeDataHasRequiredKeys(): void
    {
        $nodeA = Node::make(id: 'a', label: 'A', type: 'class', file: 'a.php');
        $nodeB = Node::make(id: 'b', label: 'B', type: 'class', file: 'b.php');
        $edge  = new Edge(id: 'e_0', source: 'a', target: 'b', type: 'extends', label: 'extends');

        $graph = $this->buildGraph([$nodeA, $nodeB], [$edge]);
        $this->exporter->export($graph, $this->outputPath);
        $decoded  = json_decode(file_get_contents($this->outputPath), true);
        $edgeData = $decoded['edges'][0]['data'];

        foreach (['id', 'source', 'target', 'type', 'label'] as $key) {
            $this->assertArrayHasKey($key, $edgeData, "Missing edge key: $key");
        }
    }

    public function testExport_AnalyzedAt_IsIsoFormat(): void
    {
        $graph = $this->buildGraph();
        $this->exporter->export($graph, $this->outputPath);
        $decoded = json_decode(file_get_contents($this->outputPath), true);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $decoded['plugin']['analyzed_at']
        );
    }

    public function testExport_WithUtf8SourcePreview_IsSafe(): void
    {
        $node              = Node::make(id: 'func_foo', label: 'foo', type: 'function', file: 'foo.php');
        $node->sourcePreview = "function foo() {\n    // Unicode: こんにちは\n}";

        $graph = $this->buildGraph([$node]);
        $this->exporter->export($graph, $this->outputPath);
        $decoded = json_decode(file_get_contents($this->outputPath), true);

        $this->assertNotNull($decoded['nodes'][0]['data']['source_preview']);
        $this->assertStringContainsString('こんにちは', $decoded['nodes'][0]['data']['source_preview']);
    }

    public function testExport_NodeMetadata_PreservesSuppliedValues(): void
    {
        $node = Node::make(
            id: 'class_Foo',
            label: 'Foo',
            type: 'class',
            file: 'foo.php',
            metadata: ['namespace' => 'MyPlugin', 'extends' => 'WP_Widget'],
        );

        $graph = $this->buildGraph([$node]);
        $this->exporter->export($graph, $this->outputPath);
        $decoded  = json_decode(file_get_contents($this->outputPath), true);
        $metadata = $decoded['nodes'][0]['data']['metadata'];

        $this->assertSame('MyPlugin', $metadata['namespace']);
        $this->assertSame('WP_Widget', $metadata['extends']);
    }
}
