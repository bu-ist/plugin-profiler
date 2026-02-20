<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Export\JsonExporter;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\GraphBuilder;
use PluginProfiler\Graph\PluginMetadata;
use PluginProfiler\Parser\PluginParser;
use PluginProfiler\Parser\Visitors\BlockJsonVisitor;
use PluginProfiler\Parser\Visitors\ClassVisitor;
use PluginProfiler\Parser\Visitors\DataSourceVisitor;
use PluginProfiler\Parser\Visitors\ExternalInterfaceVisitor;
use PluginProfiler\Parser\Visitors\FileVisitor;
use PluginProfiler\Parser\Visitors\FunctionVisitor;
use PluginProfiler\Parser\Visitors\HookVisitor;
use PluginProfiler\Parser\Visitors\JavaScriptVisitor;
use PluginProfiler\Scanner\FileScanner;

class FullPipelineTest extends TestCase
{
    private string $fixtureDir;
    private string $outputPath;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/../fixtures/sample-plugin';
        $this->outputPath = sys_get_temp_dir() . '/plugin-profiler-integration-' . uniqid() . '/graph-data.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
            @rmdir(dirname($this->outputPath));
        }
    }

    public function testFullPipeline_ParsesFixturePlugin_ProducesValidJson(): void
    {
        $scanner    = new FileScanner();
        $collection = new EntityCollection();

        // Build all PHP visitors
        $phpVisitors = [
            new ClassVisitor($collection),
            new FunctionVisitor($collection),
            new HookVisitor($collection),
            new DataSourceVisitor($collection),
            new ExternalInterfaceVisitor($collection),
            new FileVisitor($collection),
        ];
        $jsVisitor    = new JavaScriptVisitor($collection);
        $blockVisitor = new BlockJsonVisitor($collection);

        $parser = new PluginParser($collection, $phpVisitors, $jsVisitor, $blockVisitor);

        // Scan fixture
        $allFiles   = $scanner->scan($this->fixtureDir);
        $phpFiles   = $scanner->findPhpFiles($allFiles);
        $jsFiles    = $scanner->findJavaScriptFiles($allFiles);
        $blockFiles = $scanner->findBlockJsonFiles($allFiles);

        // Parse
        $parser->parseBlockJson($blockFiles);
        $parser->parseJavaScript($jsFiles);
        $parser->parsePhp($phpFiles);

        $mainFile = $scanner->identifyMainPluginFile($allFiles) ?? 'unknown.php';

        $meta = new PluginMetadata(
            name: 'Sample Plugin',
            version: '1.0.0',
            description: 'A sample plugin.',
            mainFile: basename($mainFile),
            totalFiles: count($allFiles),
            totalEntities: count($collection->getAllNodes()),
            analyzedAt: new DateTimeImmutable(),
        );

        $builder  = new GraphBuilder();
        $graph    = $builder->build($collection, $meta);
        $exporter = new JsonExporter();
        $exporter->export($graph, $this->outputPath);

        $this->assertFileExists($this->outputPath);
        $decoded = json_decode(file_get_contents($this->outputPath), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('plugin', $decoded);
        $this->assertArrayHasKey('nodes', $decoded);
        $this->assertArrayHasKey('edges', $decoded);
    }

    public function testFullPipeline_ContainsGutenbergBlockNode(): void
    {
        [$decoded] = $this->runPipeline();

        $blockNodes = array_filter(
            $decoded['nodes'],
            static fn ($n) => $n['data']['type'] === 'gutenberg_block'
        );

        $this->assertNotEmpty($blockNodes, 'Expected gutenberg_block node from block.json');
    }

    public function testFullPipeline_ContainsJsHookNode(): void
    {
        [$decoded] = $this->runPipeline();

        $hookNodes = array_filter(
            $decoded['nodes'],
            static fn ($n) => $n['data']['type'] === 'js_hook'
        );

        $this->assertNotEmpty($hookNodes, 'Expected js_hook node from index.js');
    }

    public function testFullPipeline_ContainsJsApiCallNode(): void
    {
        [$decoded] = $this->runPipeline();

        $apiNodes = array_filter(
            $decoded['nodes'],
            static fn ($n) => $n['data']['type'] === 'js_api_call'
        );

        $this->assertNotEmpty($apiNodes, 'Expected js_api_call node from index.js');
    }

    public function testFullPipeline_ContainsPhpClassNode(): void
    {
        [$decoded] = $this->runPipeline();

        $classNodes = array_filter(
            $decoded['nodes'],
            static fn ($n) => $n['data']['type'] === 'class'
        );

        $this->assertNotEmpty($classNodes, 'Expected PHP class node');
    }

    public function testFullPipeline_AllEdgeTargetsExistInNodes(): void
    {
        [$decoded] = $this->runPipeline();

        $nodeIds = array_column(array_column($decoded['nodes'], 'data'), 'id');
        $nodeSet = array_flip($nodeIds);

        foreach ($decoded['edges'] as $edge) {
            $this->assertArrayHasKey(
                $edge['data']['source'],
                $nodeSet,
                "Edge source '{$edge['data']['source']}' not found in nodes"
            );
            $this->assertArrayHasKey(
                $edge['data']['target'],
                $nodeSet,
                "Edge target '{$edge['data']['target']}' not found in nodes"
            );
        }
    }

    public function testFullPipeline_NodesHaveRequiredFields(): void
    {
        [$decoded] = $this->runPipeline();

        foreach ($decoded['nodes'] as $node) {
            $data = $node['data'];
            $this->assertArrayHasKey('id', $data);
            $this->assertArrayHasKey('label', $data);
            $this->assertArrayHasKey('type', $data);
            $this->assertArrayHasKey('file', $data);
            $this->assertNotEmpty($data['id'], 'Node id must not be empty');
            $this->assertNotEmpty($data['label'], 'Node label must not be empty');
        }
    }

    /**
     * @return array{array<string, mixed>}
     */
    private function runPipeline(): array
    {
        $scanner    = new FileScanner();
        $collection = new EntityCollection();

        $phpVisitors = [
            new ClassVisitor($collection),
            new FunctionVisitor($collection),
            new HookVisitor($collection),
            new DataSourceVisitor($collection),
            new ExternalInterfaceVisitor($collection),
            new FileVisitor($collection),
        ];

        $parser = new PluginParser(
            $collection,
            $phpVisitors,
            new JavaScriptVisitor($collection),
            new BlockJsonVisitor($collection),
        );

        $allFiles = $scanner->scan($this->fixtureDir);
        $parser->parseBlockJson($scanner->findBlockJsonFiles($allFiles));
        $parser->parseJavaScript($scanner->findJavaScriptFiles($allFiles));
        $parser->parsePhp($scanner->findPhpFiles($allFiles));

        $meta = new PluginMetadata(
            name: 'Sample Plugin',
            version: '1.0.0',
            description: '',
            mainFile: 'sample-plugin.php',
            totalFiles: count($allFiles),
            totalEntities: count($collection->getAllNodes()),
            analyzedAt: new DateTimeImmutable(),
        );

        $graph = (new GraphBuilder())->build($collection, $meta);
        (new JsonExporter())->export($graph, $this->outputPath);

        $decoded = json_decode(file_get_contents($this->outputPath), true);

        return [$decoded];
    }
}
