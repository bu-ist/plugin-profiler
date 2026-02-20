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
}
