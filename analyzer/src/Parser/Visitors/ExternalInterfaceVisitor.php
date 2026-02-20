<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PluginProfiler\Graph\Node as GraphNode;

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
            'add_menu_page',
            'add_submenu_page'            => $this->handleAdminPage($node, $funcName),
            'wp_schedule_event',
            'wp_schedule_single_event'    => $this->handleCronJob($node),
            'register_post_type'          => $this->handlePostType($node),
            'register_taxonomy'           => $this->handleTaxonomy($node),
            'wp_remote_get',
            'wp_remote_post',
            'wp_remote_request'           => $this->handleHttpCall($node, $funcName),
            'add_action', 'add_filter'    => $this->handleAjaxHook($node),
            default                       => null,
        };

        return null;
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

        $methods = $this->resolveRestMethods($node->args[2]->value ?? null);
        $file    = $this->collection->getCurrentFile();

        foreach ($methods as $method) {
            $nodeId = 'rest_' . $method . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $namespace . $route);

            $this->collection->addNode(GraphNode::make(
                id: $nodeId,
                label: strtoupper($method) . ' ' . $namespace . $route,
                type: 'rest_endpoint',
                file: $file,
                line: $node->getStartLine(),
                metadata: [
                    'http_method' => strtoupper($method),
                    'route'       => $namespace . $route,
                ],
            ));
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

        $this->collection->addNode(GraphNode::make(
            id: 'shortcode_' . $tag,
            label: '[' . $tag . ']',
            type: 'shortcode',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));
    }

    private function handleAdminPage(Expr\FuncCall $node, string $funcName): void
    {
        // add_menu_page($page_title, $menu_title, $capability, $menu_slug, ...)
        // add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, ...)
        $slugIndex = $funcName === 'add_submenu_page' ? 4 : 3;

        if (!isset($node->args[$slugIndex])) {
            return;
        }

        $slug = $this->resolveStringArg($node->args[$slugIndex]->value);
        if ($slug === null) {
            return;
        }

        $titleIndex = $funcName === 'add_submenu_page' ? 1 : 0;
        $title      = isset($node->args[$titleIndex])
            ? ($this->resolveStringArg($node->args[$titleIndex]->value) ?? $slug)
            : $slug;

        $this->collection->addNode(GraphNode::make(
            id: 'admin_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $slug),
            label: $title,
            type: 'admin_page',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));
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

        $this->collection->addNode(GraphNode::make(
            id: 'cron_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $hookName),
            label: $hookName,
            type: 'cron_job',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));
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

        $this->collection->addNode(GraphNode::make(
            id: 'post_type_' . $slug,
            label: $slug,
            type: 'post_type',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));
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

        $this->collection->addNode(GraphNode::make(
            id: 'taxonomy_' . $slug,
            label: $slug,
            type: 'taxonomy',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
        ));
    }

    private function handleHttpCall(Expr\FuncCall $node, string $funcName): void
    {
        $url    = null;
        $method = match ($funcName) {
            'wp_remote_post'    => 'POST',
            'wp_remote_get'     => 'GET',
            default             => 'REQUEST',
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

        $this->collection->addNode(GraphNode::make(
            id: 'ajax_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $actionName),
            label: $actionName,
            type: 'ajax_handler',
            file: $this->collection->getCurrentFile(),
            line: $node->getStartLine(),
            metadata: [
                'hook_name' => $hookName,
            ],
        ));
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder>|null $argsArray */
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

    private function resolveStringArg(Expr $expr): ?string
    {
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }

        return null;
    }
}
