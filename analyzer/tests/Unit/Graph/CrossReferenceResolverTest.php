<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\CrossReferenceResolver;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\Node;

class CrossReferenceResolverTest extends TestCase
{
    private EntityCollection $collection;
    private CrossReferenceResolver $resolver;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->resolver   = new CrossReferenceResolver();
    }

    // ── Helper factories ───────────────────────────────────────────────────────

    private function addNode(string $id, string $type, array $metadata = [], string $file = '/plugin/src/file.php'): void
    {
        $this->collection->addNode(Node::make(
            id:       $id,
            label:    $id,
            type:     $type,
            file:     $file,
            line:     1,
            metadata: $metadata,
        ));
    }

    private function edgesOfType(string $type): array
    {
        return array_values(array_filter(
            $this->collection->getAllEdges(),
            static fn (Edge $e) => $e->type === $type,
        ));
    }

    // ── REST endpoint matching ─────────────────────────────────────────────────

    public function testResolve_WithMatchingRestPath_CreatesCallsEndpointEdge(): void
    {
        $this->addNode('js_api_1', 'js_api_call', ['route' => '/sample/v1/items'], '/plugin/src/app.js');
        $this->addNode('rest_1', 'rest_endpoint', ['route' => 'sample/v1/items']);

        $this->resolver->resolve($this->collection);

        $edges = $this->edgesOfType('calls_endpoint');
        $this->assertCount(1, $edges);
        $this->assertSame('js_api_1', $edges[0]->source);
        $this->assertSame('rest_1', $edges[0]->target);
        $this->assertSame('REST', $edges[0]->label);
    }

    public function testResolve_WithDynamicRouteSegment_MatchesStaticPrefix(): void
    {
        // The PHP route has a dynamic regex segment; the JS call uses a concrete ID.
        $this->addNode('js_api_2', 'js_api_call', ['route' => '/sample/v1/items/42'], '/plugin/src/app.js');
        $this->addNode('rest_2', 'rest_endpoint', ['route' => 'sample/v1/items/(?P<id>\\d+)']);

        $this->resolver->resolve($this->collection);

        $edges = $this->edgesOfType('calls_endpoint');
        $this->assertCount(1, $edges, 'Dynamic REST route segment should still match');
    }

    public function testResolve_WithWpJsonPrefixInJsUrl_StripsAndMatches(): void
    {
        $this->addNode('js_api_3', 'js_api_call', ['route' => '/wp-json/sample/v1/items'], '/plugin/src/app.js');
        $this->addNode('rest_3', 'rest_endpoint', ['route' => 'sample/v1/items']);

        $this->resolver->resolve($this->collection);

        $edges = $this->edgesOfType('calls_endpoint');
        $this->assertCount(1, $edges, 'wp-json/ prefix should be stripped before matching');
    }

    public function testResolve_WithNoMatchingRestPath_CreatesNoEdge(): void
    {
        $this->addNode('js_api_4', 'js_api_call', ['route' => '/totally/different/path'], '/plugin/src/app.js');
        $this->addNode('rest_4', 'rest_endpoint', ['route' => 'sample/v1/items']);

        $this->resolver->resolve($this->collection);

        $this->assertEmpty($this->edgesOfType('calls_endpoint'));
    }

    public function testResolve_WithEmptyRoute_CreatesNoEdge(): void
    {
        $this->addNode('js_api_5', 'js_api_call', ['route' => ''], '/plugin/src/app.js');
        $this->addNode('rest_5', 'rest_endpoint', ['route' => 'sample/v1/items']);

        $this->resolver->resolve($this->collection);

        $this->assertEmpty($this->edgesOfType('calls_endpoint'));
    }

    // ── AJAX handler matching ──────────────────────────────────────────────────

    public function testResolve_WithMatchingAjaxAction_CreatesCallsAjaxHandlerEdge(): void
    {
        $this->addNode('fetch_1', 'fetch_call', [
            'route' => '/wp-admin/admin-ajax.php?action=my_action',
        ], '/plugin/src/app.js');
        $this->addNode('ajax_1', 'ajax_handler', ['hook_name' => 'wp_ajax_my_action']);

        $this->resolver->resolve($this->collection);

        $edges = $this->edgesOfType('calls_ajax_handler');
        $this->assertCount(1, $edges);
        $this->assertSame('fetch_1', $edges[0]->source);
        $this->assertSame('ajax_1', $edges[0]->target);
        $this->assertSame('AJAX', $edges[0]->label);
    }

    public function testResolve_WithNoPrivHandler_MatchesBareAction(): void
    {
        $this->addNode('axios_1', 'axios_call', [
            'route' => 'https://example.com/wp-admin/admin-ajax.php?action=submit_form',
        ], '/plugin/src/app.js');
        $this->addNode('ajax_2', 'ajax_handler', ['hook_name' => 'wp_ajax_nopriv_submit_form']);

        $this->resolver->resolve($this->collection);

        $edges = $this->edgesOfType('calls_ajax_handler');
        $this->assertCount(1, $edges, 'nopriv handler should still be matched by bare action');
    }

    public function testResolve_WithNonAjaxUrl_CreatesNoEdge(): void
    {
        $this->addNode('fetch_2', 'fetch_call', [
            'route' => 'https://api.example.com/data',
        ], '/plugin/src/app.js');
        $this->addNode('ajax_3', 'ajax_handler', ['hook_name' => 'wp_ajax_my_action']);

        $this->resolver->resolve($this->collection);

        $this->assertEmpty($this->edgesOfType('calls_ajax_handler'));
    }

    public function testResolve_WithMissingActionParam_CreatesNoEdge(): void
    {
        // URL contains admin-ajax.php but no ?action= param
        $this->addNode('fetch_3', 'fetch_call', [
            'route' => '/wp-admin/admin-ajax.php',
        ], '/plugin/src/app.js');
        $this->addNode('ajax_4', 'ajax_handler', ['hook_name' => 'wp_ajax_my_action']);

        $this->resolver->resolve($this->collection);

        $this->assertEmpty($this->edgesOfType('calls_ajax_handler'));
    }

    // ── Gutenberg block matching ───────────────────────────────────────────────

    public function testResolve_WithMatchingBlockNames_CreatesJsBlockMatchesPHPEdge(): void
    {
        $this->addNode('block_js_1', 'gutenberg_block', ['block_name' => 'myplugin/hero'], '/plugin/src/index.js');
        $this->addNode('block_php_1', 'gutenberg_block', ['block_name' => 'myplugin/hero'], '/plugin/src/block.php');

        $this->resolver->resolve($this->collection);

        $edges = $this->edgesOfType('js_block_matches_php');
        $this->assertCount(1, $edges);
        $this->assertSame('block_js_1', $edges[0]->source);
        $this->assertSame('block_php_1', $edges[0]->target);
        $this->assertSame('block registration', $edges[0]->label);
    }

    public function testResolve_WithDifferentBlockNames_CreatesNoEdge(): void
    {
        $this->addNode('block_js_2', 'gutenberg_block', ['block_name' => 'myplugin/hero'], '/plugin/src/index.js');
        $this->addNode('block_php_2', 'gutenberg_block', ['block_name' => 'myplugin/footer'], '/plugin/src/block.php');

        $this->resolver->resolve($this->collection);

        $this->assertEmpty($this->edgesOfType('js_block_matches_php'));
    }

    public function testResolve_WithPhpBlockSourceOnly_CreatesNoEdge(): void
    {
        // Both blocks are PHP-sourced — no JS→PHP connection should be made
        $this->addNode('block_php_3', 'gutenberg_block', ['block_name' => 'myplugin/hero'], '/plugin/src/one.php');
        $this->addNode('block_php_4', 'gutenberg_block', ['block_name' => 'myplugin/hero'], '/plugin/src/two.php');

        $this->resolver->resolve($this->collection);

        $this->assertEmpty($this->edgesOfType('js_block_matches_php'));
    }
}
