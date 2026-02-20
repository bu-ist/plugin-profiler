<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\ClassVisitor;

class ClassVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private ClassVisitor $visitor;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->visitor    = new ClassVisitor($this->collection);
    }

    private function parse(string $code): void
    {
        $this->collection->setCurrentFile('/fixture.php');
        $this->collection->setCurrentSource($code);

        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $parser->parse($code);
        if ($ast !== null) {
            $traverser->traverse($ast);
        }
    }

    public function testEnterNode_WithClass_CreatesNode(): void
    {
        $this->parse('<?php class Foo {}');

        $this->assertTrue($this->collection->hasNode('class_Foo'));
        $node = $this->collection->getNode('class_Foo');
        $this->assertSame('Foo', $node?->label);
        $this->assertSame('class', $node?->type);
    }

    public function testEnterNode_WithNamespacedClass_UsesNamespaceInId(): void
    {
        $this->parse('<?php namespace MyPlugin\Models; class Post {}');

        $this->assertTrue($this->collection->hasNode('class_MyPlugin_Models_Post'));
    }

    public function testEnterNode_WithExtends_CreatesExtendsEdge(): void
    {
        $this->parse('<?php class Child extends Parent_ {}');

        $edges = $this->collection->getAllEdges();
        $extendsEdges = array_filter($edges, static fn ($e) => $e->type === 'extends');
        $this->assertNotEmpty($extendsEdges, 'Expected at least one extends edge');
    }

    public function testEnterNode_WithImplements_CreatesEdgePerInterface(): void
    {
        $this->parse('<?php class Foo implements InterfaceA, InterfaceB {}');

        $edges = $this->collection->getAllEdges();
        $implEdges = array_filter($edges, static fn ($e) => $e->type === 'implements');
        $this->assertCount(2, $implEdges);
    }

    public function testEnterNode_WithInterface_CreatesInterfaceNode(): void
    {
        $this->parse('<?php interface MyInterface {}');

        $node = $this->collection->getNode('class_MyInterface');
        $this->assertNotNull($node);
        $this->assertSame('interface', $node?->type);
    }

    public function testEnterNode_WithTrait_CreatesTraitNode(): void
    {
        $this->parse('<?php trait MyTrait {}');

        $node = $this->collection->getNode('class_MyTrait');
        $this->assertNotNull($node);
        $this->assertSame('trait', $node?->type);
    }

    public function testEnterNode_WithDocblock_ExtractsDocblock(): void
    {
        $this->parse('<?php /** My class docblock */ class Documented {}');

        $node = $this->collection->getNode('class_Documented');
        $this->assertStringContainsString('My class docblock', $node?->docblock ?? '');
    }

    public function testEnterNode_WithAnonymousClass_SkipsNode(): void
    {
        $this->parse('<?php $obj = new class {};');

        $nodes = $this->collection->getAllNodes();
        $this->assertEmpty($nodes, 'Anonymous classes should be skipped');
    }

    public function testEnterNode_WithExtendsMetadata_StoresExtends(): void
    {
        $this->parse('<?php class Child extends ParentClass {}');

        $node = $this->collection->getNode('class_Child');
        $this->assertSame('ParentClass', $node?->metadata['extends']);
    }

    public function testEnterNode_WithImplementsMetadata_StoresImplements(): void
    {
        $this->parse('<?php class Foo implements Bar, Baz {}');

        $node = $this->collection->getNode('class_Foo');
        $this->assertContains('Bar', $node?->metadata['implements'] ?? []);
        $this->assertContains('Baz', $node?->metadata['implements'] ?? []);
    }
}
