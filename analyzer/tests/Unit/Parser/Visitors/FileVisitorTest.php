<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\FileVisitor;

class FileVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private FileVisitor $visitor;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->visitor    = new FileVisitor($this->collection);
    }

    private function parse(string $code, string $currentFile = '/plugin/main.php'): void
    {
        $this->collection->setCurrentFile($currentFile);
        $this->collection->setCurrentSource($code);

        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $parser->parse($code);
        if ($ast !== null) {
            $traverser->traverse($ast);
        }
    }

    public function testEnterNode_WithRequire_CreatesIncludesEdge(): void
    {
        $this->parse('<?php require "includes/class-foo.php";');

        $edges = $this->collection->getAllEdges();
        $includeEdges = array_filter($edges, static fn ($e) => $e->type === 'includes');
        $this->assertNotEmpty($includeEdges);
    }

    public function testEnterNode_WithIncludeOnce_CreatesIncludesEdge(): void
    {
        $this->parse('<?php include_once "includes/helper.php";');

        $edges = $this->collection->getAllEdges();
        $includeEdges = array_filter($edges, static fn ($e) => $e->type === 'includes');
        $this->assertNotEmpty($includeEdges);
    }

    public function testEnterNode_WithStringLiteralPath_CreatesFileNodes(): void
    {
        $this->parse('<?php require "includes/class-foo.php";');

        $nodes = $this->collection->getAllNodes();
        $fileNodes = array_filter($nodes, static fn ($n) => $n->type === 'file');
        $this->assertNotEmpty($fileNodes);
    }

    public function testEnterNode_WithDynamicPath_CreatesEdgeWithDynamicTarget(): void
    {
        $this->parse('<?php require $dynamic_path;');

        $edges = $this->collection->getAllEdges();
        $includeEdges = array_filter($edges, static fn ($e) => $e->type === 'includes');
        $this->assertNotEmpty($includeEdges);

        $edge = reset($includeEdges);
        $this->assertStringContainsString('dynamic', $edge->target);
    }

    public function testEnterNode_BothSourceAndTargetFileNodesExist(): void
    {
        $this->parse('<?php require "something.php";');

        $nodes = $this->collection->getAllNodes();
        $fileNodes = array_filter($nodes, static fn ($n) => $n->type === 'file');
        $this->assertCount(2, $fileNodes, 'Expected source and target file nodes');
    }
}
