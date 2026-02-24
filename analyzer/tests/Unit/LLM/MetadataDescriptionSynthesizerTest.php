<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\LLM;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;
use PluginProfiler\Graph\PluginMetadata;
use PluginProfiler\LLM\MetadataDescriptionSynthesizer;

class MetadataDescriptionSynthesizerTest extends TestCase
{
    private MetadataDescriptionSynthesizer $synthesizer;

    protected function setUp(): void
    {
        $this->synthesizer = new MetadataDescriptionSynthesizer();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function buildGraph(array $nodes, array $edges = []): Graph
    {
        return new Graph(
            nodes: $nodes,
            edges: $edges,
            plugin: new PluginMetadata(
                name: 'Test',
                version: '1.0',
                description: '',
                mainFile: 'test.php',
                totalFiles: 1,
                totalEntities: count($nodes),
                analyzedAt: new DateTimeImmutable(),
                hostPath: '',
                phpFiles: 1,
                jsFiles: 0,
            ),
        );
    }

    // ── Class tests ──────────────────────────────────────────────────────────

    public function testSynthesize_Class_WithNamespaceAndInheritance(): void
    {
        $node = Node::make(
            id: 'class_MyPlugin_Admin_Settings',
            label: 'Settings',
            type: 'class',
            file: '/plugin/src/Admin/Settings.php',
            metadata: [
                'namespace'  => 'MyPlugin\\Admin',
                'extends'    => 'BaseController',
                'implements' => ['Hookable', 'Configurable'],
            ],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            'PHP class in namespace MyPlugin\\Admin. Extends BaseController, implements Hookable, Configurable.',
            $node->description
        );
    }

    public function testSynthesize_Class_NoNamespace(): void
    {
        $node = Node::make(
            id: 'class_MyWidget',
            label: 'MyWidget',
            type: 'class',
            file: '/plugin/my-widget.php',
            metadata: ['namespace' => null],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('PHP class.', $node->description);
    }

    // ── Interface tests ──────────────────────────────────────────────────────

    public function testSynthesize_Interface_WithMethodCount(): void
    {
        $iface = Node::make(
            id: 'class_Hookable',
            label: 'Hookable',
            type: 'interface',
            file: '/plugin/src/Hookable.php',
            metadata: ['namespace' => 'MyPlugin'],
        );
        $method1 = Node::make(id: 'method_Hookable_register', label: 'register', type: 'method', file: '/plugin/src/Hookable.php');
        $method2 = Node::make(id: 'method_Hookable_deregister', label: 'deregister', type: 'method', file: '/plugin/src/Hookable.php');

        $edges = [
            Edge::make('class_Hookable', 'method_Hookable_register', 'has_method', 'defines'),
            Edge::make('class_Hookable', 'method_Hookable_deregister', 'has_method', 'defines'),
        ];
        $graph = $this->buildGraph([$iface, $method1, $method2], $edges);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('PHP interface in namespace MyPlugin with 2 methods.', $iface->description);
    }

    // ── Trait tests ──────────────────────────────────────────────────────────

    public function testSynthesize_Trait(): void
    {
        $node = Node::make(
            id: 'class_Singleton',
            label: 'Singleton',
            type: 'trait',
            file: '/plugin/src/Singleton.php',
            metadata: ['namespace' => 'MyPlugin\\Traits'],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('PHP trait in namespace MyPlugin\\Traits.', $node->description);
    }

    // ── Function tests ───────────────────────────────────────────────────────

    public function testSynthesize_Function_WithParamsAndReturnType(): void
    {
        $node = Node::make(
            id: 'func_get_user_data',
            label: 'get_user_data',
            type: 'function',
            file: '/plugin/functions.php',
            metadata: [
                'params'      => [
                    ['type' => 'int', 'name' => 'user_id'],
                    ['type' => 'bool', 'name' => 'include_meta'],
                ],
                'return_type' => 'array',
            ],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            'Standalone PHP function accepting 2 parameters (int $user_id, bool $include_meta), returns array.',
            $node->description
        );
    }

    // ── Method tests ─────────────────────────────────────────────────────────

    public function testSynthesize_Method_WithVisibilityAndParams(): void
    {
        $node = Node::make(
            id: 'method_UserController_update',
            label: 'update',
            type: 'method',
            file: '/plugin/src/UserController.php',
            metadata: [
                'visibility'  => 'public',
                'params'      => [['type' => 'WP_REST_Request', 'name' => 'request']],
                'return_type' => 'WP_REST_Response',
            ],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            'Public method of UserController. Accepts 1 parameter (WP_REST_Request $request), returns WP_REST_Response.',
            $node->description
        );
    }

    // ── Hook tests ───────────────────────────────────────────────────────────

    public function testSynthesize_Hook_ActionWithPriority(): void
    {
        $node = Node::make(
            id: 'hook_action_init',
            label: 'init',
            type: 'hook',
            file: '/plugin/plugin.php',
            subtype: 'action',
            metadata: ['hook_name' => 'init', 'priority' => 20],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            "WordPress action hook registered with priority 20.",
            $node->description
        );
    }

    public function testSynthesize_Hook_FilterDefaultPriority(): void
    {
        $node = Node::make(
            id: 'hook_filter_the_content',
            label: 'the_content',
            type: 'hook',
            file: '/plugin/plugin.php',
            subtype: 'filter',
            metadata: ['hook_name' => 'the_content', 'priority' => 10],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('WordPress filter hook.', $node->description);
    }

    public function testSynthesize_Hook_MultipleCallbacks(): void
    {
        $hook = Node::make(
            id: 'hook_action_init',
            label: 'init',
            type: 'hook',
            file: '/plugin/plugin.php',
            subtype: 'action',
            metadata: ['hook_name' => 'init', 'priority' => 10],
        );
        $func1 = Node::make(id: 'func_setup_a', label: 'setup_a', type: 'function', file: '/plugin/a.php');
        $func2 = Node::make(id: 'func_setup_b', label: 'setup_b', type: 'function', file: '/plugin/b.php');
        $func3 = Node::make(id: 'func_setup_c', label: 'setup_c', type: 'function', file: '/plugin/c.php');

        $edges = [
            Edge::make('func_setup_a', 'hook_action_init', 'registers_hook', 'registers'),
            Edge::make('func_setup_b', 'hook_action_init', 'registers_hook', 'registers'),
            Edge::make('func_setup_c', 'hook_action_init', 'registers_hook', 'registers'),
        ];
        $graph = $this->buildGraph([$hook, $func1, $func2, $func3], $edges);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('WordPress action hook (3 callbacks).', $hook->description);
    }

    // ── REST endpoint tests ──────────────────────────────────────────────────

    public function testSynthesize_RestEndpoint(): void
    {
        $node = Node::make(
            id: 'rest_get_wp_v2_posts',
            label: 'GET /wp/v2/posts',
            type: 'rest_endpoint',
            file: '/plugin/rest.php',
            metadata: ['http_method' => 'GET', 'route' => '/wp/v2/posts'],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('REST API endpoint: GET /wp/v2/posts.', $node->description);
    }

    public function testSynthesize_RestEndpoint_WithCapability(): void
    {
        $node = Node::make(
            id: 'rest_post_wp_v2_posts',
            label: 'POST /wp/v2/posts',
            type: 'rest_endpoint',
            file: '/plugin/rest.php',
            metadata: [
                'http_method' => 'POST',
                'route'       => '/wp/v2/posts',
                'capability'  => 'edit_posts',
            ],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            "REST API endpoint: POST /wp/v2/posts. Requires 'edit_posts' capability.",
            $node->description
        );
    }

    // ── AJAX handler tests ───────────────────────────────────────────────────

    public function testSynthesize_AjaxHandler_Authenticated(): void
    {
        $node = Node::make(
            id: 'ajax_my_save',
            label: 'my_save',
            type: 'ajax_handler',
            file: '/plugin/ajax.php',
            metadata: ['hook_name' => 'wp_ajax_my_save'],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            "AJAX handler for action 'my_save'. Authenticated users only (wp_ajax_*).",
            $node->description
        );
    }

    public function testSynthesize_AjaxHandler_NoPriv(): void
    {
        $node = Node::make(
            id: 'ajax_public_action',
            label: 'public_action',
            type: 'ajax_handler',
            file: '/plugin/ajax.php',
            metadata: ['hook_name' => 'wp_ajax_nopriv_public_action'],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            "AJAX handler for action 'public_action'. Accessible to unauthenticated users (nopriv).",
            $node->description
        );
    }

    // ── Data source tests ────────────────────────────────────────────────────

    public function testSynthesize_DataSource_ReadOption(): void
    {
        $node = Node::make(
            id: 'data_read_my_settings',
            label: 'my_settings',
            type: 'data_source',
            file: '/plugin/settings.php',
            subtype: 'option',
            metadata: ['operation' => 'read', 'key' => 'my_settings'],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame("Reads WordPress option 'my_settings'.", $node->description);
    }

    public function testSynthesize_DataSource_WriteDatabase(): void
    {
        $node = Node::make(
            id: 'data_write_dynamic_42',
            label: 'dynamic key',
            type: 'data_source',
            file: '/plugin/db.php',
            subtype: 'database',
            metadata: ['operation' => 'write', 'key' => null],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('Writes to database.', $node->description);
    }

    public function testSynthesize_DataSource_DeleteTransient(): void
    {
        $node = Node::make(
            id: 'data_delete_my_cache',
            label: 'my_cache',
            type: 'data_source',
            file: '/plugin/cache.php',
            subtype: 'transient',
            metadata: ['operation' => 'delete', 'key' => 'my_cache'],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame("Deletes WordPress transient 'my_cache'.", $node->description);
    }

    // ── HTTP call tests ──────────────────────────────────────────────────────

    public function testSynthesize_HttpCall(): void
    {
        $node = Node::make(
            id: 'http_get_api_42',
            label: 'GET https://api.example.com/v1',
            type: 'http_call',
            file: '/plugin/api.php',
            metadata: ['http_method' => 'GET', 'route' => 'https://api.example.com/v1'],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            'Outbound HTTP GET request to https://api.example.com/v1.',
            $node->description
        );
    }

    // ── Block tests ──────────────────────────────────────────────────────────

    public function testSynthesize_Block_WithCategory(): void
    {
        $node = Node::make(
            id: 'block_my_plugin_hero',
            label: 'my-plugin/hero-section',
            type: 'gutenberg_block',
            file: '/plugin/blocks/hero/block.json',
            metadata: [
                'block_name'     => 'my-plugin/hero-section',
                'block_category' => 'widgets',
            ],
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame(
            "Gutenberg block 'my-plugin/hero-section'. Category: widgets.",
            $node->description
        );
    }

    // ── File tests ───────────────────────────────────────────────────────────

    public function testSynthesize_File_Script(): void
    {
        $node = Node::make(
            id: 'script_my_plugin_admin',
            label: 'my-plugin-admin',
            type: 'file',
            file: '/plugin/js/admin.js',
            subtype: 'script',
        );
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('Enqueued JavaScript asset.', $node->description);
    }

    public function testSynthesize_File_WithIncludes(): void
    {
        $fileA = Node::make(id: 'file_plugin_php', label: 'plugin.php', type: 'file', file: '/plugin/plugin.php');
        $fileB = Node::make(id: 'file_includes_functions', label: 'functions.php', type: 'file', file: '/plugin/includes/functions.php');
        $fileC = Node::make(id: 'file_includes_admin', label: 'admin.php', type: 'file', file: '/plugin/includes/admin.php');

        $edges = [
            Edge::make('file_plugin_php', 'file_includes_functions', 'includes', 'includes'),
            Edge::make('file_plugin_php', 'file_includes_admin', 'includes', 'includes'),
        ];
        $graph = $this->buildGraph([$fileA, $fileB, $fileC], $edges);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('PHP file. Includes 2 other files.', $fileA->description);
    }

    // ── Shortcode / admin page / cron / post type / taxonomy ─────────────────

    public function testSynthesize_Shortcode(): void
    {
        $node = Node::make(id: 'shortcode_gallery', label: 'gallery', type: 'shortcode', file: '/plugin/sc.php');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('WordPress shortcode [gallery].', $node->description);
    }

    public function testSynthesize_PostType(): void
    {
        $node = Node::make(id: 'post_type_portfolio', label: 'portfolio', type: 'post_type', file: '/plugin/cpt.php');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame("Custom post type 'portfolio'.", $node->description);
    }

    public function testSynthesize_Taxonomy(): void
    {
        $node = Node::make(id: 'taxonomy_project_category', label: 'project_category', type: 'taxonomy', file: '/plugin/tax.php');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame("Custom taxonomy 'project_category'.", $node->description);
    }

    // ── Skip nodes that already have descriptions ────────────────────────────

    public function testSynthesize_SkipsNodesWithExistingDescription(): void
    {
        $node = Node::make(id: 'class_Foo', label: 'Foo', type: 'class', file: '/plugin/Foo.php');
        $node->description = 'Existing LLM description.';
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('Existing LLM description.', $node->description);
    }

    // ── JS node types ────────────────────────────────────────────────────────

    public function testSynthesize_ReactComponent(): void
    {
        $node = Node::make(id: 'react_HeroBlock', label: 'HeroBlock', type: 'react_component', file: '/plugin/js/HeroBlock.jsx');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame("React component 'HeroBlock'.", $node->description);
    }

    public function testSynthesize_WpStore(): void
    {
        $node = Node::make(id: 'store_core_editor', label: 'core/editor', type: 'wp_store', file: '/plugin/js/store.js');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame("WordPress data store 'core/editor'.", $node->description);
    }

    // ── JS file detection ───────────────────────────────────────────────────

    public function testSynthesize_File_JsFileDetected(): void
    {
        $node = Node::make(id: 'file_app_js', label: 'app.js', type: 'file', file: '/plugin/js/app.js');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('JavaScript file.', $node->description);
    }

    public function testSynthesize_File_TsxFileDetected(): void
    {
        $node = Node::make(id: 'file_component_tsx', label: 'Component.tsx', type: 'file', file: '/plugin/src/Component.tsx');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('JavaScript file.', $node->description);
    }

    public function testSynthesize_File_PhpFileDefault(): void
    {
        $node = Node::make(id: 'file_main_php', label: 'main.php', type: 'file', file: '/plugin/main.php');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('PHP file.', $node->description);
    }

    // ── Compound node types ────────────────────────────────────────────────

    public function testSynthesize_DirNode(): void
    {
        $node = Node::make(id: 'dir_js_components', label: 'js/components', type: 'dir', file: '');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('JavaScript directory group.', $node->description);
    }

    public function testSynthesize_NamespaceNode(): void
    {
        $node = Node::make(id: 'ns_MyPlugin_Admin', label: 'MyPlugin\\Admin', type: 'namespace', file: '');
        $graph = $this->buildGraph([$node]);

        $this->synthesizer->synthesize($graph);

        $this->assertSame('PHP namespace group.', $node->description);
    }
}
