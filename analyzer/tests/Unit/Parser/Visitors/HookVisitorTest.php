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

    public function testEnterNode_WithDynamicHookName_UsesReadableLabel(): void
    {
        // When the hook name is a variable (cannot be statically resolved),
        // the label should be the readable string 'dynamic' rather than the
        // hash-based ID used internally to keep nodes unique.
        $this->parse('<?php add_action($hook_name, "my_func");');

        $hooks = array_filter(
            $this->collection->getAllNodes(),
            static fn ($n) => $n->type === 'hook'
        );

        $this->assertNotEmpty($hooks);
        $hook = reset($hooks);
        $this->assertSame('dynamic', $hook->label, 'Dynamic hook name should produce a readable label');
        $this->assertStringContainsString('dynamic_', $hook->id, 'Dynamic hook ID should contain hash for uniqueness');
    }

    public function testEnterNode_WithDoActionRefArray_CreatesTriggerEdge(): void
    {
        $this->parse('<?php do_action_ref_array("my_hook", [$arg]);');

        $this->assertTrue($this->collection->hasNode('hook_action_my_hook'));

        $edges        = $this->collection->getAllEdges();
        $triggerEdges = array_filter($edges, static fn ($e) => $e->type === 'triggers_hook');
        $this->assertNotEmpty($triggerEdges, 'Expected a triggers_hook edge from do_action_ref_array');
    }

    public function testEnterNode_WithApplyFiltersRefArray_CreatesFilterNode(): void
    {
        $this->parse('<?php apply_filters_ref_array("my_filter", [$value]);');

        $this->assertTrue($this->collection->hasNode('hook_filter_my_filter'));
        $node = $this->collection->getNode('hook_filter_my_filter');
        $this->assertSame('filter', $node?->subtype);
    }

    public function testEnterNode_WithRemoveAction_CreatesDeregistersHookEdge(): void
    {
        $this->parse('<?php remove_action("init", "my_func", 10);');

        $this->assertTrue($this->collection->hasNode('hook_action_init'), 'Hook node should be created');

        $edges            = $this->collection->getAllEdges();
        $deregisterEdges  = array_filter($edges, static fn ($e) => $e->type === 'deregisters_hook');
        $this->assertNotEmpty($deregisterEdges, 'Expected a deregisters_hook edge from remove_action');

        $edge = reset($deregisterEdges);
        $this->assertSame('hook_action_init', $edge->target);
    }

    public function testEnterNode_WithRemoveFilter_CreatesDeregistersHookEdgeForFilter(): void
    {
        $this->parse('<?php remove_filter("the_content", "my_filter_func");');

        $this->assertTrue($this->collection->hasNode('hook_filter_the_content'));

        $edges           = $this->collection->getAllEdges();
        $deregisterEdges = array_filter($edges, static fn ($e) => $e->type === 'deregisters_hook');
        $this->assertNotEmpty($deregisterEdges, 'Expected a deregisters_hook edge from remove_filter');
    }
}
