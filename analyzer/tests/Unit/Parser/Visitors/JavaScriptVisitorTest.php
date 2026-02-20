<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\JavaScriptVisitor;

class JavaScriptVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private JavaScriptVisitor $visitor;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->visitor    = new JavaScriptVisitor($this->collection);
    }

    private function parse(string $code, string $file = '/plugin/src/index.js'): void
    {
        $this->visitor->parse($code, $file);
    }

    // ---- registerBlockType ----

    public function testParse_WithRegisterBlockType_CreatesGutenbergBlockNode(): void
    {
        $this->parse("registerBlockType( 'my-plugin/my-block', {} );");

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');

        $this->assertNotEmpty($blockNodes, 'Expected a gutenberg_block node');
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

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');

        $this->assertEmpty($blockNodes, 'Dynamic block name should be skipped');
    }

    // ---- addAction ----

    public function testParse_WithAddAction_CreatesJsHookNode(): void
    {
        $this->parse("addAction( 'my_action', 'my-plugin', function() {} );");

        $nodes     = $this->collection->getAllNodes();
        $hookNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_hook');

        $this->assertNotEmpty($hookNodes, 'Expected a js_hook node');
    }

    public function testParse_WithAddAction_NodeSubtypeIsAction(): void
    {
        $this->parse("addAction( 'my_action', 'my-plugin', function() {} );");

        $nodes     = $this->collection->getAllNodes();
        $hookNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_hook');
        $hookNode  = reset($hookNodes);

        $this->assertSame('action', $hookNode->subtype);
    }

    public function testParse_WithAddAction_NodeIdContainsHookName(): void
    {
        $this->parse("addAction( 'my_action', 'my-plugin', function() {} );");

        $this->assertTrue(
            $this->collection->hasNode('js_hook_action_my_action'),
            'Expected node js_hook_action_my_action'
        );
    }

    // ---- addFilter ----

    public function testParse_WithAddFilter_CreatesJsHookNode(): void
    {
        $this->parse("addFilter( 'my_filter', 'my-plugin', function( v ) { return v; } );");

        $nodes     = $this->collection->getAllNodes();
        $hookNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_hook');

        $this->assertNotEmpty($hookNodes, 'Expected a js_hook node for addFilter');
    }

    public function testParse_WithAddFilter_NodeSubtypeIsFilter(): void
    {
        $this->parse("addFilter( 'my_filter', 'my-plugin', function( v ) { return v; } );");

        $nodes     = $this->collection->getAllNodes();
        $hookNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_hook');
        $hookNode  = reset($hookNodes);

        $this->assertSame('filter', $hookNode->subtype);
    }

    // ---- wp.hooks.addAction (MemberExpression chain) ----

    public function testParse_WithWpHooksAddAction_CreatesJsHookNode(): void
    {
        $this->parse("wp.hooks.addAction( 'my_action', 'my-plugin', function() {} );");

        $nodes     = $this->collection->getAllNodes();
        $hookNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_hook');

        $this->assertNotEmpty($hookNodes, 'Expected js_hook node for wp.hooks.addAction');
    }

    public function testParse_WithWpHooksAddFilter_CreatesJsHookNode(): void
    {
        $this->parse("wp.hooks.addFilter( 'my_filter', 'my-plugin', function(v) { return v; } );");

        $nodes     = $this->collection->getAllNodes();
        $hookNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_hook');

        $this->assertNotEmpty($hookNodes, 'Expected js_hook node for wp.hooks.addFilter');
    }

    // ---- Dynamic hook names ----

    public function testParse_WithDynamicHookName_SkipsHookCreation(): void
    {
        $this->parse("addAction( dynamicHookName, 'my-plugin', function() {} );");

        $nodes     = $this->collection->getAllNodes();
        $hookNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_hook');

        $this->assertEmpty($hookNodes, 'Dynamic hook name should not create a node');
    }

    // ---- apiFetch ----

    public function testParse_WithApiFetch_CreatesJsApiCallNode(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts', method: 'GET' } );");

        $nodes    = $this->collection->getAllNodes();
        $apiNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_api_call');

        $this->assertNotEmpty($apiNodes, 'Expected a js_api_call node');
    }

    public function testParse_WithApiFetch_NodeLabelContainsMethodAndPath(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts', method: 'GET' } );");

        $nodes    = $this->collection->getAllNodes();
        $apiNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_api_call');
        $apiNode  = reset($apiNodes);

        $this->assertSame('GET /wp/v2/posts', $apiNode->label);
    }

    public function testParse_WithApiFetch_MetadataHasRoute(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts', method: 'GET' } );");

        $nodes    = $this->collection->getAllNodes();
        $apiNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_api_call');
        $apiNode  = reset($apiNodes);

        $this->assertSame('/wp/v2/posts', $apiNode->metadata['route']);
    }

    public function testParse_WithApiFetch_DefaultMethodIsGet(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts' } );");

        $nodes    = $this->collection->getAllNodes();
        $apiNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_api_call');
        $apiNode  = reset($apiNodes);

        $this->assertSame('GET', $apiNode->metadata['http_method']);
    }

    public function testParse_WithApiFetch_PostMethod_SetsMethod(): void
    {
        $this->parse("apiFetch( { path: '/wp/v2/posts', method: 'POST' } );");

        $nodes    = $this->collection->getAllNodes();
        $apiNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_api_call');
        $apiNode  = reset($apiNodes);

        $this->assertSame('POST', $apiNode->metadata['http_method']);
    }

    public function testParse_WithApiFetch_WithoutPath_SkipsNode(): void
    {
        $this->parse("apiFetch( { method: 'GET' } );");

        $nodes    = $this->collection->getAllNodes();
        $apiNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_api_call');

        $this->assertEmpty($apiNodes, 'apiFetch without path should not create a node');
    }

    public function testParse_WithApiFetch_NonObjectArg_SkipsNode(): void
    {
        $this->parse("apiFetch( '/wp/v2/posts' );");

        $nodes    = $this->collection->getAllNodes();
        $apiNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_api_call');

        $this->assertEmpty($apiNodes, 'apiFetch with non-object arg should be skipped');
    }

    // ---- Full fixture file ----

    public function testParse_WithFullFixtureFile_DetectsAllExpectedNodes(): void
    {
        $fixtureCode = file_get_contents(
            __DIR__ . '/../../../fixtures/sample-plugin/src/index.js'
        );
        $this->parse($fixtureCode);

        $nodes = $this->collection->getAllNodes();
        $types = array_unique(array_map(static fn ($n) => $n->type, $nodes));

        $this->assertContains('gutenberg_block', $types, 'Expected gutenberg_block node from fixture');
        $this->assertContains('js_hook', $types, 'Expected js_hook node from fixture');
        $this->assertContains('js_api_call', $types, 'Expected js_api_call node from fixture');
    }

    public function testParse_WithFullFixtureFile_CorrectHookCount(): void
    {
        $fixtureCode = file_get_contents(
            __DIR__ . '/../../../fixtures/sample-plugin/src/index.js'
        );
        $this->parse($fixtureCode);

        $nodes     = $this->collection->getAllNodes();
        $hookNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_hook');

        // fixture has addAction + addFilter = 2 hooks
        $this->assertCount(2, $hookNodes, 'Expected 2 js_hook nodes from fixture');
    }

    public function testParse_WithFullFixtureFile_CorrectApiCallCount(): void
    {
        $fixtureCode = file_get_contents(
            __DIR__ . '/../../../fixtures/sample-plugin/src/index.js'
        );
        $this->parse($fixtureCode);

        $nodes    = $this->collection->getAllNodes();
        $apiNodes = array_filter($nodes, static fn ($n) => $n->type === 'js_api_call');

        // fixture has GET /sample/v1/items + POST /sample/v1/items = 2 API calls
        $this->assertCount(2, $apiNodes, 'Expected 2 js_api_call nodes from fixture');
    }

    // ---- Syntax errors ----

    public function testParse_WithSyntaxError_SkipsFileGracefully(): void
    {
        $this->parse("this is not valid javascript @@@@");

        // Expect no exception thrown and an empty collection
        $this->assertEmpty($this->collection->getAllNodes());
    }
}
