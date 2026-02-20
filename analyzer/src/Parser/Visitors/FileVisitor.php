<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Node as GraphNode;

class FileVisitor extends NamespaceAwareVisitor
{
    private string $pluginRoot = '';

    public function setPluginRoot(string $root): void
    {
        $this->pluginRoot = $root;
    }

    public function enterNode(Node $node): ?int
    {
        parent::enterNode($node);

        if (!$node instanceof Expr\Include_) {
            return null;
        }

        $this->handleInclude($node);

        return null;
    }

    private function handleInclude(Expr\Include_ $node): void
    {
        $currentFile    = $this->collection->getCurrentFile();
        $currentRelPath = $this->toRelative($currentFile);
        $currentNodeId  = 'file_' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $currentRelPath);

        // Ensure current file node exists
        $this->collection->addNode(GraphNode::make(
            id: $currentNodeId,
            label: basename($currentFile),
            type: 'file',
            file: $currentFile,
            line: 0,
        ));

        $includedPath  = $this->resolveIncludePath($node->expr, $currentFile);
        $includedRelPath = $includedPath !== null ? $this->toRelative($includedPath) : null;
        $includedNodeId = 'file_' . preg_replace(
            '/[^a-zA-Z0-9_\-.]/',
            '_',
            $includedRelPath ?? ('dynamic_' . $node->getStartLine())
        );

        $this->collection->addNode(GraphNode::make(
            id: $includedNodeId,
            label: $includedPath !== null ? basename($includedPath) : 'dynamic',
            type: 'file',
            file: $includedPath ?? $currentFile,
            line: $node->getStartLine(),
        ));

        $this->collection->addEdge(
            Edge::make($currentNodeId, $includedNodeId, 'includes', 'includes')
        );
    }

    private function resolveIncludePath(Expr $expr, string $currentFile): ?string
    {
        $currentDir = dirname($currentFile);

        // Simple string literal: include 'file.php'
        if ($expr instanceof Scalar\String_) {
            $resolved = realpath($currentDir . '/' . $expr->value)
                ?: $currentDir . '/' . $expr->value;

            return $resolved;
        }

        // __DIR__ . '/path'  or  dirname(__FILE__) . '/path'
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left  = $expr->left;
            $right = $expr->right;

            $leftStr  = $this->resolveStaticMagicConstDir($left, $currentFile);
            $rightStr = $right instanceof Scalar\String_ ? $right->value : null;

            if ($leftStr !== null && $rightStr !== null) {
                $resolved = realpath($leftStr . $rightStr) ?: $leftStr . $rightStr;

                return $resolved;
            }
        }

        return null;
    }

    private function resolveStaticMagicConstDir(Expr $expr, string $currentFile): ?string
    {
        $currentDir = dirname($currentFile);

        // __DIR__
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return $currentDir;
        }

        // dirname(__FILE__)
        if ($expr instanceof Expr\FuncCall
            && $expr->name instanceof Node\Name
            && $expr->name->toString() === 'dirname'
            && isset($expr->args[0])
            && $expr->args[0]->value instanceof Node\Scalar\MagicConst\File
        ) {
            return $currentDir;
        }

        return null;
    }

    private function toRelative(string $absolutePath): string
    {
        if ($this->pluginRoot !== '' && str_starts_with($absolutePath, $this->pluginRoot)) {
            return ltrim(substr($absolutePath, strlen($this->pluginRoot)), '/\\');
        }

        return $absolutePath;
    }
}
