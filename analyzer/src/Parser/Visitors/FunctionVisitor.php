<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt;
use PhpParser\Node\UnionType;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Node as GraphNode;

class FunctionVisitor extends NamespaceAwareVisitor
{
    public function enterNode(Node $node): ?int
    {
        parent::enterNode($node);

        if ($node instanceof Stmt\ClassMethod) {
            $this->handleMethod($node);
        } elseif ($node instanceof Stmt\Function_) {
            $this->handleFunction($node);
        } elseif ($node instanceof Expr\StaticCall) {
            $this->handleStaticCall($node);
        } elseif ($node instanceof Expr\New_) {
            $this->handleNewExpression($node);
        } elseif ($node instanceof Expr\FuncCall) {
            $this->handleFuncCall($node);
        }

        return null;
    }

    /**
     * Detect ClassName::method() calls and create a 'calls' edge from the
     * enclosing method/function (or file node at file scope) to the called class node.
     * This connects classes that collaborate via static accessors (e.g. singletons).
     */
    private function handleStaticCall(Expr\StaticCall $node): void
    {
        // Only handle named classes (not self/static/parent — those are intra-class)
        if (!$node->class instanceof Node\Name) {
            return;
        }

        $calledClass = $node->class->toString();
        if (in_array($calledClass, ['self', 'static', 'parent'], true)) {
            return;
        }

        $this->ensureFileNode();
        $classId = 'class_' . $calledClass;
        $this->collection->addEdge(Edge::make($this->currentCallerOrFileId(), $classId, 'calls', 'calls'));
    }

    /**
     * Detect regular function calls and create a 'calls' edge.
     *
     * The edge target is `func_{name}` — GraphBuilder silently drops edges whose
     * target doesn't exist, so calls to WordPress API functions, PHP builtins, and
     * any other external functions are automatically filtered out.  Only calls to
     * functions defined within the analyzed plugin/theme survive.
     *
     * At file scope (procedural template code), the source is the file node,
     * connecting otherwise-orphan template files to the functions they use.
     */
    private function handleFuncCall(Expr\FuncCall $node): void
    {
        $funcName = $this->getFuncCallName($node);
        if ($funcName === null) {
            return;
        }

        // Skip very common WordPress/PHP functions that would never be user-defined.
        // These pollute the graph even though GraphBuilder would eventually prune them,
        // and creating thousands of edges that are all dropped wastes memory.
        if ($this->isCommonApiFunction($funcName)) {
            return;
        }

        $targetId = 'func_' . $funcName;

        $this->ensureFileNode();
        $this->collection->addEdge(
            Edge::make($this->currentCallerOrFileId(), $targetId, 'calls', 'calls')
        );
    }

    /**
     * Returns true for WordPress core template tags, PHP builtins, and common
     * framework functions that are never user-defined.  This is a performance
     * optimisation — GraphBuilder would drop these edges anyway since no matching
     * target node exists, but skipping them here avoids creating tens of thousands
     * of dead edges in large codebases.
     */
    private function isCommonApiFunction(string $name): bool
    {
        // WordPress template tags / core API — high-frequency, never user-defined
        static $skip = [
            // Template tags
            'get_header', 'get_footer', 'get_sidebar', 'get_template_part',
            'the_title', 'the_content', 'the_excerpt', 'the_permalink',
            'the_post_thumbnail', 'the_ID', 'the_post',
            'have_posts', 'the_post', 'wp_reset_postdata', 'wp_reset_query',
            // Loop / query
            'query_posts', 'get_posts', 'get_post', 'setup_postdata',
            'wp_query', 'get_query_var', 'is_main_query',
            // Conditional tags
            'is_single', 'is_page', 'is_home', 'is_front_page', 'is_archive',
            'is_category', 'is_tag', 'is_author', 'is_search', 'is_404',
            'is_admin', 'is_user_logged_in', 'is_multisite',
            // Escaping / sanitization
            'esc_html', 'esc_attr', 'esc_url', 'esc_js', 'esc_textarea',
            'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e',
            'wp_kses', 'wp_kses_post', 'wp_kses_allowed_html',
            'sanitize_text_field', 'sanitize_email', 'sanitize_title',
            'sanitize_file_name', 'sanitize_key', 'absint', 'intval',
            // i18n
            '__', '_e', '_x', '_n', '_nx', 'esc_html__', 'esc_html_e',
            'esc_attr__', 'esc_attr_e', '_ex',
            // URLs / paths
            'home_url', 'site_url', 'admin_url', 'plugins_url',
            'plugin_dir_path', 'plugin_dir_url', 'plugin_basename',
            'get_template_directory', 'get_template_directory_uri',
            'get_stylesheet_directory', 'get_stylesheet_directory_uri',
            // Scripts / styles
            'wp_enqueue_script', 'wp_enqueue_style', 'wp_register_script',
            'wp_register_style', 'wp_localize_script', 'wp_dequeue_script',
            'wp_dequeue_style', 'wp_add_inline_style', 'wp_add_inline_script',
            // PHP builtins (most common in WP themes)
            'isset', 'empty', 'unset', 'var_dump', 'print_r', 'echo',
            'printf', 'sprintf', 'array_merge', 'array_map', 'array_filter',
            'array_key_exists', 'in_array', 'str_contains', 'str_starts_with',
            'str_replace', 'preg_match', 'preg_replace', 'substr', 'strlen',
            'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper',
            'json_encode', 'json_decode', 'wp_json_encode',
            'implode', 'explode', 'count', 'is_array', 'is_string',
            'defined', 'define', 'constant',
            'file_exists', 'is_readable', 'basename', 'dirname', 'pathinfo',
            'class_exists', 'function_exists', 'method_exists',
            'apply_filters', 'do_action', 'add_action', 'add_filter',
            'remove_action', 'remove_filter',
            'wp_nonce_field', 'wp_verify_nonce', 'check_ajax_referer',
            'current_user_can', 'wp_get_current_user', 'get_current_user_id',
            'wp_die', 'wp_redirect', 'wp_safe_redirect',
            'wp_send_json', 'wp_send_json_success', 'wp_send_json_error',
            'checked', 'selected', 'disabled',
            'get_option', 'update_option', 'add_option', 'delete_option',
            'get_post_meta', 'update_post_meta', 'add_post_meta', 'delete_post_meta',
            'get_user_meta', 'update_user_meta',
            'get_transient', 'set_transient', 'delete_transient',
            'wp_cache_get', 'wp_cache_set', 'wp_cache_delete',
            'do_shortcode', 'shortcode_atts',
            'register_post_type', 'register_taxonomy',
            'register_rest_route', 'register_block_type',
            'wp_schedule_event', 'wp_next_scheduled',
            'require', 'require_once', 'include', 'include_once',
        ];

        return in_array($name, $skip, true);
    }

    /**
     * Detect `new ClassName()` expressions and create an 'instantiates' edge from
     * the enclosing method/function to the instantiated class.
     *
     * Skips:
     *  - Anonymous classes (`new class {}`) — no stable target node.
     *  - `new self()` / `new static()` / `new parent()` — intra-class noise.
     *  - Instantiation at file scope — no meaningful source node to draw from.
     */
    private function handleNewExpression(Expr\New_ $node): void
    {
        if (!$node->class instanceof Node\Name) {
            return; // Anonymous class expression — skip
        }

        $className = $node->class->toString();
        if (in_array($className, ['self', 'static', 'parent'], true)) {
            return; // Intra-class reference — adds noise without value
        }

        $this->ensureFileNode();
        $classId = 'class_' . $className;
        $this->collection->addEdge(Edge::make($this->currentCallerOrFileId(), $classId, 'instantiates', 'new'));
    }

    private function handleMethod(Stmt\ClassMethod $node): void
    {
        if ($this->currentClass === '') {
            return;
        }

        $methodName = $node->name->toString();
        $methodId   = 'method_' . $this->currentClass . '_' . $methodName;
        $classId    = $this->classId($this->currentClass);
        $file       = $this->collection->getCurrentFile();

        $metadata = [
            'visibility'  => $this->resolveVisibility($node->flags),
            'params'      => $this->resolveParams($node->params),
            'return_type' => $this->resolveType($node->returnType),
        ];

        $graphNode = GraphNode::make(
            id: $methodId,
            label: $methodName,
            type: 'method',
            file: $file,
            line: $node->getStartLine(),
            metadata: $metadata,
            docblock: $node->getDocComment()?->getText(),
        );
        $graphNode->sourcePreview = $this->extractSourcePreview($node);

        $this->collection->addNode($graphNode);
        $this->collection->addEdge(Edge::make($classId, $methodId, 'has_method', 'has'));
    }

    private function handleFunction(Stmt\Function_ $node): void
    {
        $name = $node->name->toString();
        $id   = 'func_' . $name;
        $file = $this->collection->getCurrentFile();

        $metadata = [
            'params'      => $this->resolveParams($node->params),
            'return_type' => $this->resolveType($node->returnType),
        ];

        $graphNode = GraphNode::make(
            id: $id,
            label: $name,
            type: 'function',
            file: $file,
            line: $node->getStartLine(),
            metadata: $metadata,
            docblock: $node->getDocComment()?->getText(),
        );
        $graphNode->sourcePreview = $this->extractSourcePreview($node);

        $this->collection->addNode($graphNode);
    }

    private function resolveVisibility(int $flags): string
    {
        if ($flags & Stmt\Class_::MODIFIER_PRIVATE) {
            return 'private';
        }
        if ($flags & Stmt\Class_::MODIFIER_PROTECTED) {
            return 'protected';
        }

        return 'public';
    }

    /**
     * @param array<\PhpParser\Node\Param> $params
     * @return array<array{name: string, type: string|null}>
     */
    private function resolveParams(array $params): array
    {
        return array_map(function ($param) {
            return [
                'name' => '$' . $param->var->name,
                'type' => $this->resolveType($param->type),
            ];
        }, $params);
    }

    private function resolveType(null|Node $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof NullableType) {
            $inner = $this->resolveType($type->type);

            return $inner !== null ? '?' . $inner : null;
        }

        if ($type instanceof UnionType) {
            return implode('|', array_map(fn ($t) => $this->resolveType($t) ?? '', $type->types));
        }

        if ($type instanceof IntersectionType) {
            return implode('&', array_map(fn ($t) => $this->resolveType($t) ?? '', $type->types));
        }

        if ($type instanceof Node\Name) {
            return $type->toString();
        }

        return null;
    }
}
