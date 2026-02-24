<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Graph;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;
use PluginProfiler\Graph\PluginMetadata;
use PluginProfiler\Graph\SecurityAnnotator;

class SecurityAnnotatorTest extends TestCase
{
    private SecurityAnnotator $annotator;

    protected function setUp(): void
    {
        $this->annotator = new SecurityAnnotator();
    }

    private function makeGraph(array $nodes, array $edges = []): Graph
    {
        return new Graph(
            nodes: $nodes,
            edges: $edges,
            plugin: new PluginMetadata(
                name: 'Test Plugin',
                version: '1.0.0',
                description: '',
                mainFile: 'test.php',
                totalFiles: 1,
                totalEntities: count($nodes),
                analyzedAt: new DateTimeImmutable(),
            ),
        );
    }

    private function makeNode(string $id, string $type, ?string $sourcePreview = null, array $metadata = []): Node
    {
        $node = Node::make(
            id: $id,
            label: $id,
            type: $type,
            file: '/plugin/test.php',
            line: 1,
            metadata: $metadata,
        );
        $node->sourcePreview = $sourcePreview;

        return $node;
    }

    // ── Capability detection ──────────────────────────────────────────────

    public function testAnnotate_DetectsCurrentUserCan(): void
    {
        $node = $this->makeNode('func_check_perms', 'function', <<<'PHP'
            function check_perms() {
                if ( ! current_user_can( 'edit_posts' ) ) {
                    wp_die( 'No access' );
                }
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertSame('edit_posts', $node->metadata['capability']);
    }

    public function testAnnotate_DetectsUserCan(): void
    {
        $node = $this->makeNode('func_user_check', 'function', <<<'PHP'
            function user_check( $user_id ) {
                if ( user_can( $user_id, 'manage_options' ) ) {
                    return true;
                }
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertSame('manage_options', $node->metadata['capability']);
    }

    public function testAnnotate_NoCapabilityWhenAbsent(): void
    {
        $node = $this->makeNode('func_no_cap', 'function', <<<'PHP'
            function no_cap() {
                return 'hello';
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertNull($node->metadata['capability'] ?? null);
    }

    // ── Nonce detection ──────────────────────────────────────────────────

    public function testAnnotate_DetectsWpVerifyNonce(): void
    {
        $node = $this->makeNode('func_nonce', 'function', <<<'PHP'
            function save_data() {
                if ( ! wp_verify_nonce( $_POST['nonce'], 'save_action' ) ) {
                    die;
                }
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertTrue($node->metadata['nonce_verified']);
    }

    public function testAnnotate_DetectsCheckAjaxReferer(): void
    {
        $node = $this->makeNode('func_ajax_ref', 'function', <<<'PHP'
            function handle_ajax() {
                check_ajax_referer( 'my_nonce', 'security' );
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertTrue($node->metadata['nonce_verified']);
    }

    public function testAnnotate_DetectsCheckAdminReferer(): void
    {
        $node = $this->makeNode('func_admin_ref', 'function', <<<'PHP'
            function admin_save() {
                check_admin_referer( 'admin_action' );
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertTrue($node->metadata['nonce_verified']);
    }

    public function testAnnotate_NoNonceWhenAbsent(): void
    {
        $node = $this->makeNode('func_no_nonce', 'function', <<<'PHP'
            function no_nonce() {
                echo 'hello';
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertArrayNotHasKey('nonce_verified', $node->metadata);
    }

    // ── Sanitization detection ──────────────────────────────────────────

    public function testAnnotate_CountsSanitizationCalls(): void
    {
        $node = $this->makeNode('func_sanitize', 'function', <<<'PHP'
            function save_input( $data ) {
                $name  = sanitize_text_field( $data['name'] );
                $email = sanitize_email( $data['email'] );
                $html  = wp_kses_post( $data['content'] );
                return compact( 'name', 'email', 'html' );
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertSame(3, $node->metadata['sanitization_count']);
    }

    public function testAnnotate_CountsEscapingFunctions(): void
    {
        $node = $this->makeNode('func_escape', 'function', <<<'PHP'
            function render() {
                echo esc_html( $title );
                echo esc_attr( $class );
                echo esc_url( $link );
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertSame(3, $node->metadata['sanitization_count']);
    }

    public function testAnnotate_CountsAbsintAndIntval(): void
    {
        $node = $this->makeNode('func_int', 'function', <<<'PHP'
            function get_item( $id ) {
                $id = absint( $id );
                $page = intval( $_GET['page'] );
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertSame(2, $node->metadata['sanitization_count']);
    }

    public function testAnnotate_ZeroSanitizationWhenAbsent(): void
    {
        $node = $this->makeNode('func_no_sanitize', 'function', <<<'PHP'
            function raw() {
                echo $_POST['data'];
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertArrayNotHasKey('sanitization_count', $node->metadata);
    }

    // ── Method nodes ─────────────────────────────────────────────────────

    public function testAnnotate_WorksOnMethodNodes(): void
    {
        $node = $this->makeNode('method_MyClass_handle', 'method', <<<'PHP'
            public function handle( $request ) {
                if ( ! current_user_can( 'publish_posts' ) ) {
                    return new WP_Error( 'no_access' );
                }
                check_ajax_referer( 'my_nonce' );
                $title = sanitize_text_field( $request['title'] );
            }
            PHP);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertSame('publish_posts', $node->metadata['capability']);
        $this->assertTrue($node->metadata['nonce_verified']);
        $this->assertSame(1, $node->metadata['sanitization_count']);
    }

    // ── Skips non-function/method nodes ──────────────────────────────────

    public function testAnnotate_SkipsClassNodes(): void
    {
        $node = $this->makeNode('class_Foo', 'class', 'class Foo { current_user_can("edit_posts"); }');

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertArrayNotHasKey('capability', $node->metadata);
    }

    public function testAnnotate_SkipsNodesWithoutSourcePreview(): void
    {
        $node = $this->makeNode('func_empty', 'function', null);

        $graph = $this->makeGraph([$node]);
        $this->annotator->annotate($graph);

        $this->assertArrayNotHasKey('capability', $node->metadata);
    }

    // ── Propagation to endpoints ─────────────────────────────────────────

    public function testAnnotate_PropagatesToRestEndpoint(): void
    {
        $funcNode = $this->makeNode('func_register_routes', 'function', <<<'PHP'
            function register_routes() {
                if ( ! current_user_can( 'edit_posts' ) ) { return; }
                check_admin_referer( 'rest_nonce' );
                $val = sanitize_text_field( $_GET['q'] );
            }
            PHP);

        $endpointNode = $this->makeNode('rest_get_my_api_items', 'rest_endpoint', null, [
            'http_method' => 'GET',
            'route'       => '/my-api/items',
        ]);

        $edge = Edge::make(
            'func_register_routes',
            'rest_get_my_api_items',
            'registers_rest',
            'registers'
        );

        $graph = $this->makeGraph([$funcNode, $endpointNode], [$edge]);
        $this->annotator->annotate($graph);

        // Function should be annotated
        $this->assertSame('edit_posts', $funcNode->metadata['capability']);

        // Endpoint should inherit annotations from registering function
        $this->assertSame('edit_posts', $endpointNode->metadata['capability']);
        $this->assertTrue($endpointNode->metadata['nonce_verified']);
        $this->assertSame(1, $endpointNode->metadata['sanitization_count']);
    }

    public function testAnnotate_PropagatesToAjaxHandler(): void
    {
        $funcNode = $this->makeNode('func_handle_save', 'function', <<<'PHP'
            function handle_save() {
                check_ajax_referer( 'save_nonce', 'security' );
                $data = sanitize_text_field( $_POST['data'] );
                $more = esc_html( $_POST['more'] );
            }
            PHP);

        $ajaxNode = $this->makeNode('ajax_my_save', 'ajax_handler', null, [
            'hook_name' => 'wp_ajax_my_save',
        ]);

        $edge = Edge::make(
            'func_handle_save',
            'ajax_my_save',
            'registers_ajax',
            'registers'
        );

        $graph = $this->makeGraph([$funcNode, $ajaxNode], [$edge]);
        $this->annotator->annotate($graph);

        $this->assertTrue($ajaxNode->metadata['nonce_verified']);
        $this->assertSame(2, $ajaxNode->metadata['sanitization_count']);
    }

    public function testAnnotate_DoesNotOverrideExistingCapability(): void
    {
        $funcNode = $this->makeNode('func_reg', 'function', <<<'PHP'
            function reg() {
                current_user_can( 'manage_options' );
            }
            PHP);

        // Endpoint already has capability set (e.g. from permission_callback extraction)
        $endpointNode = $this->makeNode('rest_get_endpoint', 'rest_endpoint', null, [
            'capability' => 'edit_posts',
        ]);

        $edge = Edge::make('func_reg', 'rest_get_endpoint', 'registers_rest', 'registers');
        $graph = $this->makeGraph([$funcNode, $endpointNode], [$edge]);
        $this->annotator->annotate($graph);

        // Original should be preserved
        $this->assertSame('edit_posts', $endpointNode->metadata['capability']);
    }

    public function testAnnotate_IgnoresNonRegisterEdges(): void
    {
        $funcNode = $this->makeNode('func_caller', 'function', <<<'PHP'
            function caller() {
                current_user_can( 'manage_options' );
            }
            PHP);

        $endpointNode = $this->makeNode('rest_get_items', 'rest_endpoint');

        // 'calls' is not a register edge — should not propagate
        $edge = Edge::make('func_caller', 'rest_get_items', 'calls', 'calls');
        $graph = $this->makeGraph([$funcNode, $endpointNode], [$edge]);
        $this->annotator->annotate($graph);

        $this->assertArrayNotHasKey('capability', $endpointNode->metadata);
    }
}
