<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\BlockJsonVisitor;

class BlockJsonVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private BlockJsonVisitor $visitor;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->visitor    = new BlockJsonVisitor($this->collection);
    }

    public function testParse_WithFullBlockJson_CreatesGutenbergBlockNode(): void
    {
        $fixture = __DIR__ . '/../../../fixtures/sample-plugin/block.json';
        $this->visitor->parse($fixture);

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');

        $this->assertNotEmpty($blockNodes, 'Expected a gutenberg_block node');
        $blockNode = reset($blockNodes);
        $this->assertSame('sample-plugin/sample-block', $blockNode->metadata['block_name']);
        $this->assertSame('Sample Block', $blockNode->label);
    }

    public function testParse_WithFullBlockJson_SetsBlockCategory(): void
    {
        $fixture = __DIR__ . '/../../../fixtures/sample-plugin/block.json';
        $this->visitor->parse($fixture);

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');
        $blockNode  = reset($blockNodes);

        $this->assertSame('widgets', $blockNode->metadata['block_category']);
    }

    public function testParse_WithFullBlockJson_SetsBlockAttributes(): void
    {
        $fixture = __DIR__ . '/../../../fixtures/sample-plugin/block.json';
        $this->visitor->parse($fixture);

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');
        $blockNode  = reset($blockNodes);

        $this->assertIsArray($blockNode->metadata['block_attributes']);
        $this->assertArrayHasKey('message', $blockNode->metadata['block_attributes']);
    }

    public function testParse_WithRenderTemplate_CreatesEdgeToPhpFile(): void
    {
        $fixture = __DIR__ . '/../../../fixtures/sample-plugin/block.json';
        $this->visitor->parse($fixture);

        $edges        = $this->collection->getAllEdges();
        $renderEdges  = array_filter($edges, static fn ($e) => $e->type === 'renders_block');

        $this->assertNotEmpty($renderEdges, 'Expected a renders_block edge');
    }

    public function testParse_WithRenderTemplate_CreatesTargetFileNode(): void
    {
        $fixture = __DIR__ . '/../../../fixtures/sample-plugin/block.json';
        $this->visitor->parse($fixture);

        $nodes     = $this->collection->getAllNodes();
        $fileNodes = array_filter($nodes, static fn ($n) => $n->type === 'file');

        $renderNodes = array_filter(
            $fileNodes,
            static fn ($n) => str_contains($n->label, 'render.php')
        );

        $this->assertNotEmpty($renderNodes, 'Expected a file node for render.php');
    }

    public function testParse_WithEditorScript_CreatesEdgeToJsFile(): void
    {
        $fixture = __DIR__ . '/../../../fixtures/sample-plugin/block.json';
        $this->visitor->parse($fixture);

        $edges        = $this->collection->getAllEdges();
        $scriptEdges  = array_filter($edges, static fn ($e) => $e->type === 'enqueues_script');

        $this->assertNotEmpty($scriptEdges, 'Expected an enqueues_script edge');
    }

    public function testParse_WithEditorScript_CreatesJsFileNode(): void
    {
        $fixture = __DIR__ . '/../../../fixtures/sample-plugin/block.json';
        $this->visitor->parse($fixture);

        $nodes     = $this->collection->getAllNodes();
        $fileNodes = array_filter($nodes, static fn ($n) => $n->type === 'file');

        $jsNodes = array_filter(
            $fileNodes,
            static fn ($n) => str_contains($n->label, 'index.js')
        );

        $this->assertNotEmpty($jsNodes, 'Expected a file node for index.js');
    }

    public function testParse_WithInvalidJson_SkipsGracefully(): void
    {
        $tmpFile = sys_get_temp_dir() . '/invalid_block_' . uniqid() . '.json';
        file_put_contents($tmpFile, '{invalid json}');

        $this->visitor->parse($tmpFile);

        $this->assertEmpty($this->collection->getAllNodes());

        unlink($tmpFile);
    }

    public function testParse_WithMissingNameField_SkipsGracefully(): void
    {
        $tmpFile = sys_get_temp_dir() . '/noname_block_' . uniqid() . '.json';
        file_put_contents($tmpFile, json_encode(['title' => 'No Name Block']));

        $this->visitor->parse($tmpFile);

        $this->assertEmpty($this->collection->getAllNodes());

        unlink($tmpFile);
    }

    public function testParse_WithNonExistentFile_SkipsGracefully(): void
    {
        $this->visitor->parse('/nonexistent/path/block.json');

        $this->assertEmpty($this->collection->getAllNodes());
    }

    public function testParse_WithMinimalBlockJson_CreatesNode(): void
    {
        $tmpFile = sys_get_temp_dir() . '/minimal_block_' . uniqid() . '.json';
        file_put_contents($tmpFile, json_encode([
            'name'  => 'my-plugin/minimal-block',
            'title' => 'Minimal Block',
        ]));

        $this->visitor->parse($tmpFile);

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');

        $this->assertCount(1, $blockNodes);

        unlink($tmpFile);
    }

    public function testParse_BlockNodeId_UsesSlashSeparatedName(): void
    {
        $tmpFile = sys_get_temp_dir() . '/named_block_' . uniqid() . '.json';
        file_put_contents($tmpFile, json_encode([
            'name'  => 'my-plugin/my-block',
            'title' => 'My Block',
        ]));

        $this->visitor->parse($tmpFile);

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');
        $blockNode  = reset($blockNodes);

        $this->assertSame('block_my-plugin_my-block', $blockNode->id);

        unlink($tmpFile);
    }

    public function testParse_BlockNodeDocblock_UsesDescriptionField(): void
    {
        $fixture = __DIR__ . '/../../../fixtures/sample-plugin/block.json';
        $this->visitor->parse($fixture);

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');
        $blockNode  = reset($blockNodes);

        $this->assertSame('A simple sample block for testing.', $blockNode->docblock);
    }
}
