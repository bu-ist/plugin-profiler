<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Node as GraphNode;

class HookVisitor extends NamespaceAwareVisitor
{
    private const REGISTER_FUNCS = ['add_action', 'add_filter'];
    private const TRIGGER_FUNCS  = ['do_action', 'apply_filters'];
    private const ALL_HOOK_FUNCS = ['add_action', 'add_filter', 'do_action', 'apply_filters'];

    public function enterNode(Node $node): ?int
    {
        parent::enterNode($node);

        if (!$node instanceof Expr\FuncCall) {
            return null;
        }

        $funcName = $this->getFuncCallName($node);
        if ($funcName === null || !in_array($funcName, self::ALL_HOOK_FUNCS, true)) {
            return null;
        }

        $this->handleHookCall($node, $funcName);

        return null;
    }

    private function handleHookCall(Expr\FuncCall $node, string $funcName): void
    {
        // Arg 0: hook name
        if (!isset($node->args[0])) {
            return;
        }

        $hookNameArg = $node->args[0]->value;
        $hookName    = $this->resolveHookName($hookNameArg, $node);
        $isFilter    = in_array($funcName, ['add_filter', 'apply_filters'], true);
        $hookType    = $isFilter ? 'filter' : 'action';
        $hookId      = 'hook_' . $hookType . '_' . $hookName;

        $file = $this->collection->getCurrentFile();

        // Create or ensure hook node exists
        $hookNode = GraphNode::make(
            id: $hookId,
            label: $hookName,
            type: 'hook',
            file: $file,
            line: $node->getStartLine(),
            subtype: $hookType,
            metadata: [
                'hook_name' => $hookName,
                'priority'  => $this->resolvePriority($node),
            ],
        );
        $this->collection->addNode($hookNode);

        $isRegister = in_array($funcName, self::REGISTER_FUNCS, true);

        if ($isRegister) {
            // add_action / add_filter: edge from callback → hook
            if (!isset($node->args[1])) {
                return;
            }

            $callbackArg = $node->args[1]->value;
            $callbackIds = $this->resolveCallback($callbackArg, $node);

            foreach ($callbackIds as $callbackId) {
                $this->collection->addEdge(Edge::make($callbackId, $hookId, 'registers_hook', 'registers'));
            }

            // If this is a wp_ajax_* hook, also link the hook node to the ajax_handler node
            if (str_starts_with($hookName, 'wp_ajax_')) {
                $isNoPriv   = str_starts_with($hookName, 'wp_ajax_nopriv_');
                $actionName = $isNoPriv
                    ? substr($hookName, strlen('wp_ajax_nopriv_'))
                    : substr($hookName, strlen('wp_ajax_'));
                $ajaxId = 'ajax_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $actionName);

                // Edge: hook → ajax_handler (hook triggers the ajax handler)
                if ($this->collection->hasNode($ajaxId)) {
                    $this->collection->addEdge(Edge::make($hookId, $ajaxId, 'triggers_handler', 'triggers'));
                }
            }
        } else {
            // do_action / apply_filters: edge from caller → hook
            $callerId = $this->currentCallerId();
            if ($callerId !== null) {
                $this->collection->addEdge(Edge::make($callerId, $hookId, 'triggers_hook', 'triggers'));
            }
        }
    }

    /**
     * @return array<string>
     */
    private function resolveCallback(Expr $callbackArg, Expr\FuncCall $node): array
    {
        $file = $this->collection->getCurrentFile();

        // String callback: 'function_name'
        if ($callbackArg instanceof Scalar\String_) {
            $funcId = 'func_' . $callbackArg->value;

            return [$funcId];
        }

        // Array callback: [$class, 'method'] or [$this, 'method']
        if ($callbackArg instanceof Expr\Array_ && count($callbackArg->items) === 2) {
            $classItem  = $callbackArg->items[0]?->value;
            $methodItem = $callbackArg->items[1]?->value;

            if (!$classItem instanceof Expr || !$methodItem instanceof Scalar\String_) {
                return [];
            }

            $methodName = $methodItem->value;
            $className  = $this->resolveCallbackClass($classItem);

            if ($className !== null) {
                $methodId = 'method_' . $className . '_' . $methodName;

                return [$methodId];
            }
        }

        // Closure or arrow function: create anonymous function node
        if ($callbackArg instanceof Expr\Closure || $callbackArg instanceof Expr\ArrowFunction) {
            $anonId = sprintf(
                'func_anonymous_%s_%d',
                md5($file),
                $callbackArg->getStartLine()
            );
            $this->collection->addNode(GraphNode::make(
                id: $anonId,
                label: 'anonymous@' . basename($file) . ':' . $callbackArg->getStartLine(),
                type: 'function',
                file: $file,
                line: $callbackArg->getStartLine(),
            ));

            return [$anonId];
        }

        return [];
    }

    private function resolveCallbackClass(Expr $classExpr): ?string
    {
        // $this → use current class
        if ($classExpr instanceof Expr\Variable && $classExpr->name === 'this') {
            return $this->currentClass !== '' ? $this->currentClass : null;
        }

        // __CLASS__ magic constant
        if ($classExpr instanceof Node\Scalar\MagicConst\Class_) {
            return $this->currentClass !== '' ? $this->currentClass : null;
        }

        // 'ClassName' or ClassName::class
        if ($classExpr instanceof Scalar\String_) {
            return $classExpr->value;
        }

        if ($classExpr instanceof Expr\ClassConstFetch) {
            if ($classExpr->class instanceof Node\Name) {
                $name = $classExpr->class->toString();
                if ($name === 'self' || $name === 'static') {
                    return $this->currentClass !== '' ? $this->currentClass : null;
                }

                return $name;
            }
        }

        return null;
    }

    private function resolveHookName(Expr $hookNameArg, Expr\FuncCall $node): string
    {
        if ($hookNameArg instanceof Scalar\String_) {
            return $hookNameArg->value;
        }

        // Dynamic hook name
        $file = basename($this->collection->getCurrentFile());

        return 'dynamic_' . md5($file) . '_' . $node->getStartLine();
    }

    private function resolvePriority(Expr\FuncCall $node): int
    {
        if (!isset($node->args[2])) {
            return 10;
        }

        $priorityArg = $node->args[2]->value;
        if ($priorityArg instanceof Scalar\LNumber) {
            return $priorityArg->value;
        }

        return 10;
    }
}
