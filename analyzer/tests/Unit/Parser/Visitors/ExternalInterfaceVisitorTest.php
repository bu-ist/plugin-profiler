<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\ExternalInterfaceVisitor;

class ExternalInterfaceVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private ExternalInterfaceVisitor $visitor;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->visitor    = new ExternalInterfaceVisitor($this->collection);
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

    public function testEnterNode_WithRegisterRestRoute_CreatesRestEndpoint(): void
    {
        $this->parse('<?php register_rest_route("my-plugin/v1", "/items", ["methods" => "GET", "callback" => "get_items"]);');

        $nodes = $this->collection->getAllNodes();
        $restNodes = array_filter($nodes, static fn ($n) => $n->type === 'rest_endpoint');
        $this->assertNotEmpty($restNodes, 'Expected a rest_endpoint node');
    }

    public function testEnterNode_WithAddShortcode_CreatesShortcodeNode(): void
    {
        $this->parse('<?php add_shortcode("my-shortcode", "my_shortcode_handler");');

        $this->assertTrue($this->collection->hasNode('shortcode_my-shortcode'));
        $node = $this->collection->getNode('shortcode_my-shortcode');
        $this->assertSame('shortcode', $node?->type);
    }

    public function testEnterNode_WithAddMenuPage_CreatesAdminPageNode(): void
    {
        $this->parse('<?php add_menu_page("My Page", "My Plugin", "manage_options", "my-plugin", "my_page_callback");');

        $nodes = $this->collection->getAllNodes();
        $adminNodes = array_filter($nodes, static fn ($n) => $n->type === 'admin_page');
        $this->assertNotEmpty($adminNodes);
    }

    public function testEnterNode_WithWpScheduleEvent_CreatesCronJobNode(): void
    {
        $this->parse('<?php wp_schedule_event(time(), "hourly", "my_cron_hook");');

        $this->assertTrue($this->collection->hasNode('cron_my_cron_hook'));
        $node = $this->collection->getNode('cron_my_cron_hook');
        $this->assertSame('cron_job', $node?->type);
    }

    public function testEnterNode_WithRegisterPostType_CreatesPostTypeNode(): void
    {
        $this->parse('<?php register_post_type("my_cpt", []);');

        $this->assertTrue($this->collection->hasNode('post_type_my_cpt'));
        $node = $this->collection->getNode('post_type_my_cpt');
        $this->assertSame('post_type', $node?->type);
    }

    public function testEnterNode_WithRegisterTaxonomy_CreatesTaxonomyNode(): void
    {
        $this->parse('<?php register_taxonomy("my_tax", "post", []);');

        $this->assertTrue($this->collection->hasNode('taxonomy_my_tax'));
        $node = $this->collection->getNode('taxonomy_my_tax');
        $this->assertSame('taxonomy', $node?->type);
    }

    public function testEnterNode_WithWpRemoteGet_CreatesHttpCallNode(): void
    {
        $this->parse('<?php wp_remote_get("https://example.com/api");');

        $nodes = $this->collection->getAllNodes();
        $httpNodes = array_filter($nodes, static fn ($n) => $n->type === 'http_call');
        $this->assertNotEmpty($httpNodes);
    }

    public function testEnterNode_WithAjaxAction_CreatesAjaxHandlerNode(): void
    {
        $this->parse('<?php add_action("wp_ajax_my_action", "handle_ajax");');

        $this->assertTrue($this->collection->hasNode('ajax_my_action'));
        $node = $this->collection->getNode('ajax_my_action');
        $this->assertSame('ajax_handler', $node?->type);
    }

    public function testEnterNode_WithAjaxNoPrivAction_CreatesAjaxHandlerNode(): void
    {
        $this->parse('<?php add_action("wp_ajax_nopriv_my_action", "handle_ajax");');

        $this->assertTrue($this->collection->hasNode('ajax_my_action'));
    }

    public function testEnterNode_WithAddOptionsPage_CreatesAdminPageNode(): void
    {
        $this->parse('<?php add_options_page("Settings", "My Plugin", "manage_options", "my-settings");');

        $nodes     = $this->collection->getAllNodes();
        $adminNodes = array_filter($nodes, static fn ($n) => $n->type === 'admin_page');
        $this->assertNotEmpty($adminNodes, 'Expected an admin_page node from add_options_page');
    }

    public function testEnterNode_WithAddThemePage_CreatesAdminPageNode(): void
    {
        $this->parse('<?php add_theme_page("Theme Options", "Theme", "manage_options", "theme-options");');

        $nodes     = $this->collection->getAllNodes();
        $adminNodes = array_filter($nodes, static fn ($n) => $n->type === 'admin_page');
        $this->assertNotEmpty($adminNodes, 'Expected an admin_page node from add_theme_page');
    }

    public function testEnterNode_WithWpSafeRemoteGet_CreatesHttpCallNode(): void
    {
        $this->parse('<?php wp_safe_remote_get("https://example.com/api");');

        $nodes     = $this->collection->getAllNodes();
        $httpNodes = array_filter($nodes, static fn ($n) => $n->type === 'http_call');
        $this->assertNotEmpty($httpNodes, 'Expected an http_call node from wp_safe_remote_get');
    }

    public function testEnterNode_WithWpRemotePost_CreatesHttpCallWithPostMethod(): void
    {
        $this->parse('<?php wp_remote_post("https://example.com/api");');

        $nodes     = $this->collection->getAllNodes();
        $httpNodes = array_filter($nodes, static fn ($n) => $n->type === 'http_call');
        $this->assertNotEmpty($httpNodes);

        $node = reset($httpNodes);
        $this->assertSame('POST', $node->metadata['http_method']);
    }

    public function testEnterNode_WithRegisterBlockType_CreatesGutenbergBlockNode(): void
    {
        $this->parse('<?php register_block_type("my-plugin/my-block", []);');

        $nodes      = $this->collection->getAllNodes();
        $blockNodes = array_filter($nodes, static fn ($n) => $n->type === 'gutenberg_block');
        $this->assertNotEmpty($blockNodes, 'Expected a gutenberg_block node from register_block_type');

        $block = reset($blockNodes);
        $this->assertSame('my-plugin/my-block', $block->label);
    }

    public function testEnterNode_WithPermissionCallbackReturnTrue_RecordsCapability(): void
    {
        $this->parse('<?php register_rest_route("my/v1", "/items", ["methods" => "GET", "callback" => "get_items", "permission_callback" => "__return_true"]);');

        $nodes = $this->collection->getAllNodes();
        $restNodes = array_values(array_filter($nodes, static fn ($n) => $n->type === 'rest_endpoint'));
        $this->assertNotEmpty($restNodes);
        $this->assertSame('__return_true', $restNodes[0]->metadata['capability']);
    }

    public function testEnterNode_WithPermissionCallbackClosure_ExtractsCapability(): void
    {
        $code = <<<'PHP'
<?php
register_rest_route("my/v1", "/items", [
    "methods" => "GET",
    "callback" => "get_items",
    "permission_callback" => function() {
        return current_user_can("edit_posts");
    }
]);
PHP;
        $this->parse($code);

        $nodes = $this->collection->getAllNodes();
        $restNodes = array_values(array_filter($nodes, static fn ($n) => $n->type === 'rest_endpoint'));
        $this->assertNotEmpty($restNodes);
        $this->assertSame('edit_posts', $restNodes[0]->metadata['capability']);
    }

    public function testEnterNode_WithoutPermissionCallback_NullCapability(): void
    {
        $this->parse('<?php register_rest_route("my/v1", "/items", ["methods" => "GET", "callback" => "get_items"]);');

        $nodes = $this->collection->getAllNodes();
        $restNodes = array_values(array_filter($nodes, static fn ($n) => $n->type === 'rest_endpoint'));
        $this->assertNotEmpty($restNodes);
        $this->assertArrayNotHasKey('capability', $restNodes[0]->metadata);
    }

    public function testEnterNode_WithWpEnqueueScript_CreatesFileNodeAndEnqueuesEdge(): void
    {
        $this->parse('<?php wp_enqueue_script("my-script", get_plugin_file_uri("js/app.js"), ["jquery"]);');

        $nodes       = $this->collection->getAllNodes();
        $scriptNodes = array_filter($nodes, static fn ($n) => $n->id === 'script_my_script');
        $this->assertNotEmpty($scriptNodes, 'Expected a script node for handle my-script');

        $edges        = $this->collection->getAllEdges();
        $enqueueEdges = array_filter($edges, static fn ($e) => $e->type === 'enqueues_script');
        $this->assertNotEmpty($enqueueEdges, 'Expected an enqueues_script edge');
    }
}
