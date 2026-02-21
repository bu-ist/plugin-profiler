<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\JavaScriptVisitor;

/**
 * JavaScriptVisitor tests.
 *
 * The visitor shells out to js-extractor.mjs (Node.js + Babel).
 * Tests write real temp files so the extractor can parse them.
 * All tests are skipped if 'node' is not available in PATH.
 */
class JavaScriptVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private JavaScriptVisitor $visitor;

    /** @var string[] Temp files to clean up */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node not available in PATH — JS tests require Node.js');
        }

        $this->collection = new EntityCollection();
        $this->visitor    = new JavaScriptVisitor($this->collection);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tmpFiles = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function nodeAvailable(): bool
    {
        return shell_exec('node --version 2>/dev/null') !== null;
    }

    private function parse(string $code, string $ext = 'js'): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jsv_') . '.' . $ext;
        file_put_contents($tmp, $code);
        $this->tmpFiles[] = $tmp;
        $this->visitor->parse($code, $tmp);
    }

    private function filterNodes(string $type): array
    {
        return array_values(array_filter(
            $this->collection->getAllNodes(),
            static fn ($n) => $n->type === $type,
        ));
    }

    // ── registerBlockType ─────────────────────────────────────────────────────

    public function testParse_WithRegisterBlockType_CreatesGutenbergBlockNode(): void
    {
        $this->parse("registerBlockType( 'my-plugin/my-block', {} );");

        $this->assertNotEmpty($this->filterNodes('gutenberg_block'), 'Expected a gutenberg_block node');
    }

    public function testParse_WithRegisterBlockType_NodeIdUsesBlockName(): void
    {
        $this->parse("registerBlockType( 'my-plugin/my-block', {} );");

        $this->assertTrue(
            $this->collection->hasNode('block_my-plugin_my-block'),
            'Expected node with id block_my-plugin_my-block'
        );
    }

    public function testParse_WithRegisterBlockType_LabelIsBlockName(): void
    {
        $this->parse("registerBlockType( 'my-plugin/my-block', {} );");

        $node = $this->collection->getNode('block_my-plugin_my-block');
        $this->assertNotNull($node);
        $this->assertSame('my-plugin/my-block', $node->label);
    }

    public function testParse_WithRegisterBlockType_DynamicName_SkipsNode(): void
    {
        $this->parse("registerBlockType( blockName, {} );");

        $this->assertEmpty($this->filterNodes('gutenberg_block'), 'Dynamic block name should be skipped');
    }

    // ── addAction ─────────────────────────────────────────────────────────────

    public function testParse_WithAddAction_CreatesJsHookNode(): void
    {
        $this->parse("addAction( 'my_action', 'my-plugin', function() {} );");

        $this->assertNotEmpty($this->filterNodes('js_hook'), 'Expected a js_hook node');
    }

    public function testParse_WithAddAction_NodeSubtypeIsAction(): void
    {
        $this->parse("addAction( 'my_action', 'my-plugin', function() {} );");

        $hook = $this->filterNodes('js_hook')[0] ?? null;
        $this->assertNotNull($hook);
        $this->assertSame('action', $hook->subtype);
    }

    public function testParse_WithAddAction_NodeIdContainsHookName(): void
    {
        $this->parse("addAction( 'my_action', 'my-plugin', function() {} );");

        $this->assertTrue(
            $this->collection->hasNode('js_hook_action_my_action'),
            'Expected node js_hook_action_my_action'
        );
    }

    // ── addFilter ─────────────────────────────────────────────────────────────

    public function testParse_WithAddFilter_CreatesJsHookNode(): void
    {
        $this->parse("addFilter( 'my_filter', 'my-plugin', function( v ) { return v; } );");

        $this->assertNotEmpty($this->filterNodes('js_hook'), 'Expected a js_hook node for addFilter');
    }

    public function testParse_WithAddFilter_NodeSubtypeIsFilter(): void
    {
        $this->parse("addFilter( 'my_filter', 'my-plugin', function( v ) { return v; } );");

        $hook = $this->filterNodes('js_hook')[0] ?? null;
        $this->assertNotNull($hook);
        $this->assertSame('filter', $hook->subtype);
    }

    // ── wp.hooks.addAction / addFilter ────────────────────────────────────────

    public function testParse_WithWpHooksAddAction_CreatesJsHookNode(): void
    {
        $this->parse("wp.hooks.addAction( 'my_action', 'my-plugin', function() {} );");

        $this->assertNotEmpty($this->filterNodes('js_hook'), 'Expected js_hook node for wp.hooks.addAction');
    }

    public function testParse_WithWpHooksAddFilter_CreatesJsHookNode(): void
    {
        $this->parse("wp.hooks.addFilter( 'my_filter', 'my-plugin', function(v) { return v; } );");

        $this->assertNotEmpty($this->filterNodes('js_hook'), 'Expected js_hook node for wp.hooks.addFilter');
    }

    // ── Dynamic hook names ────────────────────────────────────────────────────

    public function testParse_WithDynamicHookName_SkipsHookCreation(): void
    {
        $this->parse("addAction( dynamicHookName, 'my-plugin', function() {} );");

        $this->assertEmpty($this->filterNodes('js_hook'), 'Dynamic hook name should not create a node');
    }

    // ── apiFetch ──────────────────────────────────────────────────────────────

    public function testParse_WithApiFetch_CreatesJsApiCallNode(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts', method: 'GET' } );");

        $this->assertNotEmpty($this->filterNodes('js_api_call'), 'Expected a js_api_call node');
    }

    public function testParse_WithApiFetch_NodeLabelContainsMethodAndPath(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts', method: 'GET' } );");

        $node = $this->filterNodes('js_api_call')[0] ?? null;
        $this->assertNotNull($node);
        $this->assertSame('GET /wp/v2/posts', $node->label);
    }

    public function testParse_WithApiFetch_MetadataHasRoute(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts', method: 'GET' } );");

        $node = $this->filterNodes('js_api_call')[0] ?? null;
        $this->assertNotNull($node);
        $this->assertSame('/wp/v2/posts', $node->metadata['route']);
    }

    public function testParse_WithApiFetch_DefaultMethodIsGet(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts' } );");

        $node = $this->filterNodes('js_api_call')[0] ?? null;
        $this->assertNotNull($node);
        $this->assertSame('GET', $node->metadata['http_method']);
    }

    public function testParse_WithApiFetch_PostMethod_SetsMethod(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts', method: 'POST' } );");

        $node = $this->filterNodes('js_api_call')[0] ?? null;
        $this->assertNotNull($node);
        $this->assertSame('POST', $node->metadata['http_method']);
    }

    public function testParse_WithApiFetch_WithoutPath_SkipsNode(): void
    {
        $this->parse("apiFetch( { method: 'GET' } );");

        $this->assertEmpty($this->filterNodes('js_api_call'), 'apiFetch without path should not create a node');
    }

    // ── fetch() ───────────────────────────────────────────────────────────────

    public function testParse_WithFetch_CreatesFetchCallNode(): void
    {
        $this->parse("fetch('/api/events', { method: 'GET' });");

        $this->assertNotEmpty($this->filterNodes('fetch_call'), 'Expected a fetch_call node');
    }

    public function testParse_WithFetch_NodeLabelContainsMethodAndUrl(): void
    {
        $this->parse("fetch('/api/events', { method: 'GET' });");

        $node = $this->filterNodes('fetch_call')[0] ?? null;
        $this->assertNotNull($node);
        $this->assertSame('GET /api/events', $node->label);
    }

    public function testParse_WithFetch_DefaultMethodIsGet(): void
    {
        $this->parse("fetch('/api/events');");

        $node = $this->filterNodes('fetch_call')[0] ?? null;
        $this->assertNotNull($node);
        $this->assertSame('GET', $node->metadata['http_method']);
    }

    // ── axios ─────────────────────────────────────────────────────────────────

    public function testParse_WithAxiosPost_CreatesAxiosCallNode(): void
    {
        $this->parse("axios.post('/phpbin/calendar/rpc/create.php', data);");

        $this->assertNotEmpty($this->filterNodes('axios_call'), 'Expected an axios_call node');
    }

    public function testParse_WithAxiosPost_NodeLabelContainsMethodAndUrl(): void
    {
        $this->parse("axios.post('/phpbin/calendar/rpc/create.php', data);");

        $node = $this->filterNodes('axios_call')[0] ?? null;
        $this->assertNotNull($node);
        $this->assertSame('POST /phpbin/calendar/rpc/create.php', $node->label);
    }

    public function testParse_WithAxiosGet_SetsGetMethod(): void
    {
        $this->parse("axios.get('/api/items');");

        $node = $this->filterNodes('axios_call')[0] ?? null;
        $this->assertNotNull($node);
        $this->assertSame('GET', $node->metadata['http_method']);
    }

    // ── React components ──────────────────────────────────────────────────────

    public function testParse_WithFunctionComponentReturningJsx_CreatesReactComponentNode(): void
    {
        $this->parse(
            "function MyComponent() { return <div>Hello</div>; }",
            'jsx'
        );

        $this->assertNotEmpty($this->filterNodes('react_component'), 'Expected a react_component node');
    }

    public function testParse_WithArrowComponentReturningJsx_CreatesReactComponentNode(): void
    {
        $this->parse(
            "const MyWidget = () => <span>Hi</span>;",
            'jsx'
        );

        $this->assertNotEmpty($this->filterNodes('react_component'), 'Expected a react_component node');
    }

    public function testParse_WithPlainFunction_CreatesJsFunctionNode(): void
    {
        $this->parse("function formatDate(ts) { return new Date(ts).toISOString(); }");

        $this->assertNotEmpty($this->filterNodes('js_function'), 'Expected a js_function node');
    }

    // ── Full fixture file ─────────────────────────────────────────────────────

    public function testParse_WithFullFixtureFile_DetectsAllExpectedNodes(): void
    {
        $fixturePath = __DIR__ . '/../../../fixtures/sample-plugin/src/index.js';
        $this->visitor->parse(file_get_contents($fixturePath), $fixturePath);

        $types = array_unique(array_map(static fn ($n) => $n->type, $this->collection->getAllNodes()));

        $this->assertContains('gutenberg_block', $types, 'Expected gutenberg_block node from fixture');
        $this->assertContains('js_hook', $types, 'Expected js_hook node from fixture');
        $this->assertContains('js_api_call', $types, 'Expected js_api_call node from fixture');
    }

    public function testParse_WithFullFixtureFile_CorrectHookCount(): void
    {
        $fixturePath = __DIR__ . '/../../../fixtures/sample-plugin/src/index.js';
        $this->visitor->parse(file_get_contents($fixturePath), $fixturePath);

        // fixture has addAction + addFilter = 2 hooks
        $this->assertCount(2, $this->filterNodes('js_hook'), 'Expected 2 js_hook nodes from fixture');
    }

    public function testParse_WithFullFixtureFile_CorrectApiCallCount(): void
    {
        $fixturePath = __DIR__ . '/../../../fixtures/sample-plugin/src/index.js';
        $this->visitor->parse(file_get_contents($fixturePath), $fixturePath);

        // fixture has GET /sample/v1/items + POST /sample/v1/items = 2 API calls
        $this->assertCount(2, $this->filterNodes('js_api_call'), 'Expected 2 js_api_call nodes from fixture');
    }

    // ── Syntax errors ─────────────────────────────────────────────────────────

    public function testParse_WithSyntaxError_SkipsFileGracefully(): void
    {
        // Babel has errorRecovery:true so it may still parse partial code.
        // What matters is: no exception thrown and we don't crash.
        $this->parse("@@@@ totally invalid @@@@");

        // Just confirm no exception was thrown
        $this->assertTrue(true);
    }
}
