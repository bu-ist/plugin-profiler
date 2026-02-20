<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PluginProfiler\Graph\EntityCollection;

/**
 * Base visitor that tracks the current namespace and class context.
 * All visitors that need namespace/class awareness should extend this.
 */
abstract class NamespaceAwareVisitor extends NodeVisitorAbstract
{
    protected string $currentNamespace = '';
    protected string $currentClass = '';

    public function __construct(
        protected readonly EntityCollection $collection,
    ) {}

    public function beforeTraverse(array $nodes): ?array
    {
        $this->currentNamespace = '';
        $this->currentClass     = '';

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : '';
        }

        if ($node instanceof Stmt\Class_) {
            $this->currentClass = $node->name?->toString() ?? '';
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = '';
        }

        if ($node instanceof Stmt\Class_) {
            $this->currentClass = '';
        }

        return null;
    }

    /**
     * Build a deterministic class node ID respecting namespace.
     */
    protected function classId(string $name): string
    {
        if ($this->currentNamespace !== '') {
            return 'class_' . $this->currentNamespace . '_' . $name;
        }

        return 'class_' . $name;
    }

    /**
     * Extract lines from the current source for a source preview.
     */
    protected function extractSourcePreview(Node $node): string
    {
        $source = $this->collection->getCurrentSource();
        if ($source === '') {
            return '';
        }

        $lines     = explode("\n", $source);
        $startLine = max(0, $node->getStartLine() - 1); // 0-indexed
        $endLine   = min($node->getEndLine() - 1, $startLine + 29);

        return implode("\n", array_slice($lines, $startLine, $endLine - $startLine + 1));
    }

    /**
     * Safely get the string name from a function call node.
     *
     * @param \PhpParser\Node\Expr\FuncCall $node
     */
    protected function getFuncCallName(\PhpParser\Node\Expr\FuncCall $node): ?string
    {
        if ($node->name instanceof \PhpParser\Node\Name) {
            return $node->name->toString();
        }

        return null;
    }
}
