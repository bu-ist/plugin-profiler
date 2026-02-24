<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Node as GraphNode;

/**
 * Detects WordPress external interface registrations in PHP AST nodes.
 *
 * Creates graph nodes and `registers` edges for: REST endpoints, shortcodes,
 * admin pages, cron jobs, custom post types, taxonomies, outbound HTTP calls,
 * and AJAX handlers registered via `wp_ajax_*` / `wp_ajax_nopriv_*` hooks.
 */
class ExternalInterfaceVisitor extends NamespaceAwareVisitor
{
    public function enterNode(Node $node): ?int
    {
        parent::enterNode($node);

        if (!$node instanceof Expr\FuncCall) {
            return null;
        }

        $funcName = $this->getFuncCallName($node);
        if ($funcName === null) {
            return null;
        }

        match ($funcName) {
            'register_rest_route'          => $this->handleRestRoute($node),
            'add_shortcode'               => $this->handleShortcode($node),
            // All add_*_page variants — slug is always arg 3 (except add_submenu_page which is 4)
            'add_menu_page',
            'add_submenu_page',
            'add_options_page',
            'add_theme_page',
            'add_plugins_page',
            'add_management_page',
            'add_users_page',
            'add_dashboard_page',
            'add_posts_page',
            'add_media_page',
            'add_links_page',
            'add_comments_page'           => $this->handleAdminPage($node, $funcName),
            'wp_schedule_event',
            'wp_schedule_single_event'    => $this->handleCronJob($node),
            'register_post_type'          => $this->handlePostType($node),
            'register_taxonomy'           => $this->handleTaxonomy($node),
            // Standard and safe remote HTTP functions
            'wp_remote_get',
            'wp_remote_post',
            'wp_remote_request',
            'wp_remote_head',
            'wp_remote_delete',
            'wp_remote_patch',
            'wp_remote_put',
            'wp_safe_remote_get',
            'wp_safe_remote_post',
            'wp_safe_remote_request'      => $this->handleHttpCall($node, $funcName),
            // Server-side block registration
            'register_block_type',
            'register_block_type_from_metadata' => $this->handleBlockType($node),
            // Script/style enqueueing
            'wp_enqueue_script',
            'wp_register_script'          => $this->handleEnqueueScript($node),
            'wp_enqueue_style',
            'wp_register_style'           => $this->handleEnqueueStyle($node),
            'add_action', 'add_filter'    => $this->handleAjaxHook($node),
            default                       => null,
        };

        return null;
    }

    /**
     * Add an edge from the current enclosing method/function to the given node ID.
     * The $type parameter is used as both the Cytoscape edge type (for styling/filtering)
     * and falls back to a readable label.
     */
    private function addCallerEdge(string $targetId, string $type = 'registers', string $label = ''): void
    {
        $this->ensureFileNode();
        $this->collection->addEdge(
            Edge::make($this->currentCallerOrFileId(), $targetId, $type, $label ?: 'registers')
        );
    }

    private function handleRestRoute(Expr\FuncCall $node): void
    {
        if (!isset($node->args[0], $node->args[1])) {
            return;
        }

        $namespace = $this->resolveStringArg($node->args[0]->value);
        $route     = $this->resolveStringArg($node->args[1]->value);

        if ($namespace === null || $route === null) {
            return;
        }

        $methods    = $this->resolveRestMethods($node->args[2]->value ?? null);
        $capability = $this->resolvePermissionCallback($node->args[2]->value ?? null);
        $file       = $this->collection->getCurrentFile();

        foreach ($methods as $method) {
            $nodeId = 'rest_' . $method . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $namespace . $route);

            $meta = [
                'http_method' => strtoupper($method),
                'route'       => $namespace . $route,
            ];
            if ($capability !== null) {
                $meta['capability'] = $capability;
            }

            $this->collection->addNode(GraphNode::make(
                id: $nodeId,
                label: strtoupper($method) . ' ' . $namespace . $route,
                type: 'rest_endpoint',
                file: $file,
                line: $node->getStartLine(),
                metadata: $meta,
            ));

            $this->addCallerEdge($nodeId, 'registers_rest');
        }
    }

    private function handleShortcode(Expr\FuncCall $node): void
    {
        if (!isset($node->args[0])) {
            return;
        }

        $tag = $this->resolveStringArg($node->args[0]->value);
        if ($tag === null) {
            return;
        }

        $nodeId = 'shortcode_' . $tag;
        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: '[' . $tag . ']',
            type: 'shortcode',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));

        $this->addCallerEdge($nodeId, 'registers_shortcode');
    }

    private function handleAdminPage(Expr\FuncCall $node, string $funcName): void
    {
        // add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, ...)
        // All other add_*_page($page_title, $menu_title, $capability, $menu_slug, ...)
        $slugIndex  = $funcName === 'add_submenu_page' ? 4 : 3;
        $titleIndex = $funcName === 'add_submenu_page' ? 1 : 0;

        if (!isset($node->args[$slugIndex])) {
            return;
        }

        $slug = $this->resolveStringArg($node->args[$slugIndex]->value);
        if ($slug === null) {
            return;
        }

        $title      = isset($node->args[$titleIndex])
            ? ($this->resolveStringArg($node->args[$titleIndex]->value) ?? $slug)
            : $slug;

        $nodeId = 'admin_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $slug);
        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $title,
            type: 'admin_page',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));

        $this->addCallerEdge($nodeId, 'registers_page');
    }

    private function handleCronJob(Expr\FuncCall $node): void
    {
        // wp_schedule_event($timestamp, $recurrence, $hook, ...)
        // wp_schedule_single_event($timestamp, $hook, ...)
        $hookIndex = str_contains($this->getFuncCallName($node) ?? '', 'single') ? 1 : 2;

        if (!isset($node->args[$hookIndex])) {
            return;
        }

        $hookName = $this->resolveStringArg($node->args[$hookIndex]->value);
        if ($hookName === null) {
            return;
        }

        $nodeId = 'cron_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $hookName);
        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $hookName,
            type: 'cron_job',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));

        $this->addCallerEdge($nodeId, 'schedules_cron');
    }

    private function handlePostType(Expr\FuncCall $node): void
    {
        if (!isset($node->args[0])) {
            return;
        }

        $slug = $this->resolveStringArg($node->args[0]->value);
        if ($slug === null) {
            return;
        }

        $nodeId = 'post_type_' . $slug;
        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $slug,
            type: 'post_type',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));

        $this->addCallerEdge($nodeId, 'registers_post_type');
    }

    private function handleTaxonomy(Expr\FuncCall $node): void
    {
        if (!isset($node->args[0])) {
            return;
        }

        $slug = $this->resolveStringArg($node->args[0]->value);
        if ($slug === null) {
            return;
        }

        $nodeId = 'taxonomy_' . $slug;
        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $slug,
            type: 'taxonomy',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));

        $this->addCallerEdge($nodeId, 'registers_taxonomy');
    }

    private function handleHttpCall(Expr\FuncCall $node, string $funcName): void
    {
        $url    = null;
        $method = match (true) {
            str_contains($funcName, '_post')    => 'POST',
            str_contains($funcName, '_get')     => 'GET',
            str_contains($funcName, '_head')    => 'HEAD',
            str_contains($funcName, '_delete')  => 'DELETE',
            str_contains($funcName, '_patch')   => 'PATCH',
            str_contains($funcName, '_put')     => 'PUT',
            default                             => 'REQUEST',
        };

        if (isset($node->args[0])) {
            $url = $this->resolveStringArg($node->args[0]->value);
        }

        $urlLabel = $url ?? 'dynamic';
        $nodeId   = 'http_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $urlLabel . '_' . $node->getStartLine());

        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $method . ' ' . $urlLabel,
            type: 'http_call',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
            metadata: [
                'http_method' => $method,
                'route'       => $url,
            ],
        ));

        $this->addCallerEdge($nodeId, 'http_request');
    }

    /**
     * Detect AJAX handlers: add_action('wp_ajax_{name}', ...) or add_action('wp_ajax_nopriv_{name}', ...)
     */
    private function handleAjaxHook(Expr\FuncCall $node): void
    {
        if (!isset($node->args[0])) {
            return;
        }

        $hookName = $this->resolveStringArg($node->args[0]->value);
        if ($hookName === null) {
            return;
        }

        if (!str_starts_with($hookName, 'wp_ajax_')) {
            return;
        }

        $isNoPriv  = str_starts_with($hookName, 'wp_ajax_nopriv_');
        $actionName = $isNoPriv
            ? substr($hookName, strlen('wp_ajax_nopriv_'))
            : substr($hookName, strlen('wp_ajax_'));

        $nodeId = 'ajax_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $actionName);
        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $actionName,
            type: 'ajax_handler',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
            metadata: [
                'hook_name' => $hookName,
            ],
        ));

        $this->addCallerEdge($nodeId, 'registers_ajax');
    }

    /**
     * Extracts HTTP methods from the third argument of register_rest_route().
     *
     * Looks for a `methods` key inside the options array literal. Falls back
     * to `['GET']` when the argument is absent or the key cannot be resolved
     * statically (e.g. dynamic values or variable references).
     *
     * @return array<string>
     */
    private function resolveRestMethods(mixed $value): array
    {
        if ($value === null) {
            return ['GET'];
        }

        if ($value instanceof Expr\Array_) {
            foreach ($value->items as $item) {
                if ($item instanceof Node\ArrayItem && $item->key instanceof Scalar\String_ && $item->key->value === 'methods') {
                    if ($item->value instanceof Scalar\String_) {
                        return array_map('trim', explode(',', strtolower($item->value->value)));
                    }
                }
            }
        }

        return ['GET'];
    }

    /**
     * Extract permission_callback from the options array of register_rest_route().
     *
     * Handles three cases:
     * 1. String value: '__return_true' or '__return_false'
     * 2. Closure containing current_user_can('capability') → extract capability
     * 3. Array with class reference — not resolved (returns null)
     */
    private function resolvePermissionCallback(mixed $value): ?string
    {
        if ($value === null || !$value instanceof Expr\Array_) {
            return null;
        }

        foreach ($value->items as $item) {
            if (!$item instanceof Node\ArrayItem
                || !$item->key instanceof Scalar\String_
                || $item->key->value !== 'permission_callback') {
                continue;
            }

            // Case 1: String reference like '__return_true'
            if ($item->value instanceof Scalar\String_) {
                return $item->value->value;
            }

            // Case 2: Closure — scan for current_user_can('capability')
            if ($item->value instanceof Expr\Closure) {
                return $this->extractCapabilityFromClosure($item->value);
            }

            // Case 3: Array callable or variable — unresolvable
            return null;
        }

        return null;
    }

    /**
     * Scan a closure's body for current_user_can('cap') calls and return the capability.
     */
    private function extractCapabilityFromClosure(Expr\Closure $closure): ?string
    {
        foreach ($closure->stmts ?? [] as $stmt) {
            $capability = $this->findCapabilityInNode($stmt);
            if ($capability !== null) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * Recursively search an AST node for current_user_can('cap') calls.
     */
    private function findCapabilityInNode(Node $node): ?string
    {
        if ($node instanceof Expr\FuncCall) {
            $name = $this->getFuncCallName($node);
            if ($name === 'current_user_can' && isset($node->args[0])
                && $node->args[0]->value instanceof Scalar\String_) {
                return $node->args[0]->value->value;
            }
        }

        // Recurse into sub-nodes
        foreach ($node->getSubNodeNames() as $subName) {
            $subNode = $node->$subName;
            if ($subNode instanceof Node) {
                $result = $this->findCapabilityInNode($subNode);
                if ($result !== null) {
                    return $result;
                }
            } elseif (is_array($subNode)) {
                foreach ($subNode as $child) {
                    if ($child instanceof Node) {
                        $result = $this->findCapabilityInNode($child);
                        if ($result !== null) {
                            return $result;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * PHP-side block registration: register_block_type($type, $args) or
     * register_block_type_from_metadata($path, $args).
     *
     * $type can be 'namespace/block-name' (string literal) or a path to a
     * directory containing block.json.  We handle the string-literal case;
     * path-based calls are skipped (block.json is handled by BlockJsonVisitor).
     */
    private function handleBlockType(Expr\FuncCall $node): void
    {
        if (!isset($node->args[0])) {
            return;
        }

        $blockName = $this->resolveStringArg($node->args[0]->value);
        if ($blockName === null) {
            return;
        }

        // Skip bare directory paths — BlockJsonVisitor covers those
        if (str_starts_with($blockName, '/') || str_starts_with($blockName, '.')) {
            return;
        }

        $nodeId = 'block_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $blockName);
        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $blockName,
            type: 'gutenberg_block',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
            metadata: ['block_name' => $blockName],
        ));

        $this->addCallerEdge($nodeId, 'registers');
    }

    /**
     * Script registration: wp_enqueue_script($handle, $src, $deps, $ver, $args) and
     * wp_register_script($handle, $src, $deps, $ver, $strategy).
     *
     * Creates a `script` node keyed by the handle and an `enqueues_script` edge
     * from the enclosing function/file to that node.
     */
    private function handleEnqueueScript(Expr\FuncCall $node): void
    {
        if (!isset($node->args[0])) {
            return;
        }

        $handle = $this->resolveStringArg($node->args[0]->value);
        if ($handle === null) {
            return;
        }

        $nodeId = 'script_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $handle);

        if (!$this->collection->hasNode($nodeId)) {
            $this->collection->addNode(GraphNode::make(
                id: $nodeId,
                label: $handle,
                type: 'script',
                file: $this->collection->getCurrentFile(),
                line: $node->getStartLine(),
            ));
        }

        // Use enqueues_script as the Cytoscape edge type directly so it matches
        // the edge style selector and view-mode filter (not the generic 'registers' type).
        $this->ensureFileNode();
        $this->collection->addEdge(
            Edge::make($this->currentCallerOrFileId(), $nodeId, 'enqueues_script', 'enqueues')
        );
    }

    /**
     * Style registration: wp_enqueue_style($handle, ...) and wp_register_style($handle, ...).
     * Creates a `style` node for the stylesheet handle.
     */
    private function handleEnqueueStyle(Expr\FuncCall $node): void
    {
        if (!isset($node->args[0])) {
            return;
        }

        $handle = $this->resolveStringArg($node->args[0]->value);
        if ($handle === null) {
            return;
        }

        $nodeId = 'style_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $handle);

        if (!$this->collection->hasNode($nodeId)) {
            $this->collection->addNode(GraphNode::make(
                id: $nodeId,
                label: $handle,
                type: 'style',
                file: $this->collection->getCurrentFile(),
                line: $node->getStartLine(),
            ));
        }

        // Use enqueues_style as the Cytoscape edge type directly so it matches
        // the constant in EDGE_TYPE_META (same approach as enqueues_script above).
        $this->ensureFileNode();
        $this->collection->addEdge(
            Edge::make($this->currentCallerOrFileId(), $nodeId, 'enqueues_style', 'enqueues')
        );
    }

    private function resolveStringArg(Expr $expr): ?string
    {
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }

        return null;
    }
}
