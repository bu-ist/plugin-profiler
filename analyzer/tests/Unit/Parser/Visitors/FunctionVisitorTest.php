<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\FunctionVisitor;

class FunctionVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private FunctionVisitor $visitor;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->visitor    = new FunctionVisitor($this->collection);
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

    public function testEnterNode_WithStandaloneFunction_CreatesFuncNode(): void
    {
        $this->parse('<?php function my_func() {}');

        $this->assertTrue($this->collection->hasNode('func_my_func'));
        $node = $this->collection->getNode('func_my_func');
        $this->assertSame('function', $node?->type);
        $this->assertSame('my_func', $node?->label);
    }

    public function testEnterNode_WithClassMethod_CreatesMethodNode(): void
    {
        $this->parse('<?php class MyClass { public function doThing() {} }');

        $this->assertTrue($this->collection->hasNode('method_MyClass_doThing'));
        $node = $this->collection->getNode('method_MyClass_doThing');
        $this->assertSame('method', $node?->type);
    }

    public function testEnterNode_WithClassMethod_CreatesHasMethodEdge(): void
    {
        $this->parse('<?php class MyClass { public function doThing() {} }');

        $edges = $this->collection->getAllEdges();
        $hasMethodEdges = array_filter($edges, static fn ($e) => $e->type === 'has_method');
        $this->assertNotEmpty($hasMethodEdges);
    }

    public function testEnterNode_WithPrivateMethod_StoresVisibility(): void
    {
        $this->parse('<?php class Foo { private function secret() {} }');

        $node = $this->collection->getNode('method_Foo_secret');
        $this->assertSame('private', $node?->metadata['visibility']);
    }

    public function testEnterNode_WithProtectedMethod_StoresVisibility(): void
    {
        $this->parse('<?php class Foo { protected function inner() {} }');

        $node = $this->collection->getNode('method_Foo_inner');
        $this->assertSame('protected', $node?->metadata['visibility']);
    }

    public function testEnterNode_WithParams_StoresParamData(): void
    {
        $this->parse('<?php function my_func(string $name, int $count = 0) {}');

        $node   = $this->collection->getNode('func_my_func');
        $params = $node?->metadata['params'] ?? [];
        $this->assertCount(2, $params);
        $this->assertSame('$name', $params[0]['name']);
        $this->assertSame('string', $params[0]['type']);
    }

    public function testEnterNode_WithReturnType_StoresReturnType(): void
    {
        $this->parse('<?php function my_func(): array {}');

        $node = $this->collection->getNode('func_my_func');
        $this->assertSame('array', $node?->metadata['return_type']);
    }

    public function testEnterNode_WithNullableReturnType_StoresNullable(): void
    {
        $this->parse('<?php function my_func(): ?string {}');

        $node = $this->collection->getNode('func_my_func');
        $this->assertSame('?string', $node?->metadata['return_type']);
    }

    public function testEnterNode_WithDocblock_ExtractsDocblock(): void
    {
        $this->parse('<?php /** Does something */ function documented() {}');

        $node = $this->collection->getNode('func_documented');
        $this->assertStringContainsString('Does something', $node?->docblock ?? '');
    }

    public function testEnterNode_WithNewExpression_CreatesInstantiatesEdge(): void
    {
        $this->parse('<?php class Factory { public function build() { return new Product(); } }');

        $edges = $this->collection->getAllEdges();
        $instantiatesEdges = array_filter($edges, static fn ($e) => $e->type === 'instantiates');

        $this->assertCount(1, $instantiatesEdges, 'Expected exactly one instantiates edge');

        $edge = reset($instantiatesEdges);
        $this->assertSame('method_Factory_build', $edge->source);
        $this->assertSame('class_Product', $edge->target);
        $this->assertSame('new', $edge->label);
    }

    public function testEnterNode_WithAnonymousNew_SkipsEdge(): void
    {
        $this->parse('<?php class Foo { public function make() { return new class {}; } }');

        $edges = $this->collection->getAllEdges();
        $instantiatesEdges = array_filter($edges, static fn ($e) => $e->type === 'instantiates');

        $this->assertEmpty($instantiatesEdges, 'Anonymous class instantiation should produce no edge');
    }

    public function testEnterNode_WithNewOutsideFunction_CreatesEdgeFromFileNode(): void
    {
        $this->parse('<?php $obj = new SomeClass();');

        $edges = $this->collection->getAllEdges();
        $instantiatesEdges = array_filter($edges, static fn ($e) => $e->type === 'instantiates');

        $this->assertCount(1, $instantiatesEdges, 'File-scope instantiation should produce edge from file node');

        $edge = reset($instantiatesEdges);
        $this->assertStringStartsWith('file_', $edge->source, 'Source should be file node');
        $this->assertSame('class_SomeClass', $edge->target);
    }

    // ── Function call detection ──────────────────────────────────────────────

    public function testEnterNode_WithFuncCall_InsideFunction_CreatesCallsEdge(): void
    {
        $this->parse('<?php function caller() { my_custom_func(); }');

        $edges = $this->collection->getAllEdges();
        $callsEdges = array_filter($edges, static fn ($e) => $e->type === 'calls' && $e->target === 'func_my_custom_func');

        $this->assertCount(1, $callsEdges, 'Function call should produce a calls edge');

        $edge = reset($callsEdges);
        $this->assertSame('func_caller', $edge->source);
    }

    public function testEnterNode_WithFuncCall_AtFileScope_CreatesEdgeFromFileNode(): void
    {
        $this->parse('<?php my_custom_func();');

        $edges = $this->collection->getAllEdges();
        $callsEdges = array_filter($edges, static fn ($e) => $e->type === 'calls' && $e->target === 'func_my_custom_func');

        $this->assertCount(1, $callsEdges, 'File-scope function call should produce a calls edge');

        $edge = reset($callsEdges);
        $this->assertStringStartsWith('file_', $edge->source, 'Source should be file node at file scope');
    }

    public function testEnterNode_WithCommonApiCall_SkipsEdge(): void
    {
        // Common WordPress/PHP functions should be skipped for performance
        $this->parse('<?php function test() { esc_html($x); sprintf("%s", $y); }');

        $edges = $this->collection->getAllEdges();
        $callsEdges = array_filter($edges, static fn ($e) => $e->type === 'calls');

        $this->assertEmpty($callsEdges, 'Common API functions should not produce calls edges');
    }

    public function testEnterNode_WithStaticCallAtFileScope_CreatesEdgeFromFileNode(): void
    {
        $this->parse('<?php MyHelper::init();');

        $edges = $this->collection->getAllEdges();
        $callsEdges = array_filter($edges, static fn ($e) => $e->type === 'calls');

        $this->assertCount(1, $callsEdges, 'File-scope static call should produce a calls edge');

        $edge = reset($callsEdges);
        $this->assertStringStartsWith('file_', $edge->source, 'Source should be file node at file scope');
        $this->assertSame('class_MyHelper', $edge->target);
    }

    public function testEnterNode_WithMultipleFuncCalls_CreatesMultipleEdges(): void
    {
        $code = '<?php
            function orchestrator() {
                helper_a();
                helper_b();
            }
        ';
        $this->parse($code);

        $edges = $this->collection->getAllEdges();
        $callsEdges = array_filter($edges, static fn ($e) => $e->type === 'calls');
        $targets = array_map(static fn ($e) => $e->target, array_values($callsEdges));

        $this->assertContains('func_helper_a', $targets);
        $this->assertContains('func_helper_b', $targets);
    }
}
