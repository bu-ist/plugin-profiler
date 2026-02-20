<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\Node\IntersectionType;
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
        }

        return null;
    }

    /**
     * Detect ClassName::method() calls and create a 'calls' edge from the
     * enclosing method/function to the called class node.
     * This connects classes that collaborate via static accessors (e.g. singletons).
     */
    private function handleStaticCall(Expr\StaticCall $node): void
    {
        $callerId = $this->currentCallerId();
        if ($callerId === null) {
            return;
        }

        // Only handle named classes (not self/static/parent — those are intra-class)
        if (!$node->class instanceof Node\Name) {
            return;
        }

        $calledClass = $node->class->toString();
        if (in_array($calledClass, ['self', 'static', 'parent'], true)) {
            return;
        }

        $classId = 'class_' . $calledClass;
        $this->collection->addEdge(Edge::make($callerId, $classId, 'calls', 'calls'));
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
