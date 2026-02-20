<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\HookVisitor;

class HookVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private HookVisitor $visitor;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->visitor    = new HookVisitor($this->collection);
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

    public function testEnterNode_WithAddAction_CreatesHookNode(): void
    {
        $this->parse('<?php add_action("init", "my_function");');

        $this->assertTrue($this->collection->hasNode('hook_action_init'));
        $node = $this->collection->getNode('hook_action_init');
        $this->assertSame('hook', $node?->type);
        $this->assertSame('action', $node?->subtype);
    }

    public function testEnterNode_WithAddFilter_CreatesFilterHookNode(): void
    {
        $this->parse('<?php add_filter("the_content", "my_callback");');

        $this->assertTrue($this->collection->hasNode('hook_filter_the_content'));
        $node = $this->collection->getNode('hook_filter_the_content');
        $this->assertSame('filter', $node?->subtype);
    }

    public function testEnterNode_WithStringCallback_CreatesRegistersHookEdge(): void
    {
        $this->parse('<?php add_action("init", "my_function");');

        $edges = $this->collection->getAllEdges();
        $registerEdges = array_filter($edges, static fn ($e) => $e->type === 'registers_hook');
        $this->assertNotEmpty($registerEdges);

        $edge = reset($registerEdges);
        $this->assertSame('func_my_function', $edge->source);
        $this->assertSame('hook_action_init', $edge->target);
    }

    public function testEnterNode_WithArrayCallback_ClassMethod_CreatesEdge(): void
    {
        $this->parse('<?php add_action("init", ["MyClass", "myMethod"]);');

        $edges = $this->collection->getAllEdges();
        $registerEdges = array_filter($edges, static fn ($e) => $e->type === 'registers_hook');
        $this->assertNotEmpty($registerEdges);

        $edge = reset($registerEdges);
        $this->assertSame('method_MyClass_myMethod', $edge->source);
    }

    public function testEnterNode_WithThisCallback_UsesCurrentClass(): void
    {
        $this->parse('<?php class MyPlugin { public function boot() { add_action("init", [$this, "doInit"]); } }');

        $edges = $this->collection->getAllEdges();
        $registerEdges = array_filter($edges, static fn ($e) => $e->type === 'registers_hook');
        $this->assertNotEmpty($registerEdges);

        $edge = reset($registerEdges);
        $this->assertStringContainsString('MyPlugin', $edge->source);
    }

    public function testEnterNode_WithClosureCallback_CreatesAnonymousFuncNode(): void
    {
        $this->parse('<?php add_action("init", function() { return; });');

        $nodes = $this->collection->getAllNodes();
        $anonNodes = array_filter($nodes, static fn ($n) => str_starts_with($n->id, 'func_anonymous'));
        $this->assertNotEmpty($anonNodes);
    }

    public function testEnterNode_WithDoAction_CreatesTriggerEdge(): void
    {
        $this->parse('<?php do_action("custom_hook");');

        $this->assertTrue($this->collection->hasNode('hook_action_custom_hook'));
        // do_action does not create an edge to a callback, only the hook node
    }

    public function testEnterNode_WithApplyFilters_CreatesFilterNode(): void
    {
        $this->parse('<?php apply_filters("my_filter", $value);');

        $this->assertTrue($this->collection->hasNode('hook_filter_my_filter'));
    }

    public function testEnterNode_WithPriority_StoresInMetadata(): void
    {
        $this->parse('<?php add_action("init", "my_func", 20);');

        $node = $this->collection->getNode('hook_action_init');
        $this->assertSame(20, $node?->metadata['priority']);
    }
}
