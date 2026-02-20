<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Node as GraphNode;

class ClassVisitor extends NamespaceAwareVisitor
{
    public function enterNode(Node $node): ?int
    {
        parent::enterNode($node);

        if ($node instanceof Stmt\Class_) {
            $this->handleClass($node);
        } elseif ($node instanceof Stmt\Interface_) {
            $this->handleInterface($node);
        } elseif ($node instanceof Stmt\Trait_) {
            $this->handleTrait($node);
        }

        return null;
    }

    private function handleClass(Stmt\Class_ $node): void
    {
        // Skip anonymous classes
        if ($node->name === null) {
            return;
        }

        $name = $node->name->toString();
        $id   = $this->classId($name);
        $file = $this->collection->getCurrentFile();

        $metadata = [
            'namespace'  => $this->currentNamespace ?: null,
            'extends'    => $node->extends?->toString(),
            'implements' => array_map(static fn ($n) => $n->toString(), $node->implements),
        ];

        $graphNode = GraphNode::make(
            id: $id,
            label: $name,
            type: 'class',
            file: $file,
            line: $node->getStartLine(),
            metadata: $metadata,
            docblock: $node->getDocComment()?->getText(),
        );
        $graphNode->sourcePreview = $this->extractSourcePreview($node);

        $this->collection->addNode($graphNode);

        // Inheritance edges (target may not exist yet — GraphBuilder validates)
        if ($node->extends !== null) {
            $parentId = $this->classId($node->extends->getLast());
            $this->collection->addEdge(
                Edge::make($id, $parentId, 'extends', 'extends')
            );
        }

        foreach ($node->implements as $interface) {
            $interfaceId = 'class_' . $interface->toString();
            $this->collection->addEdge(
                Edge::make($id, $interfaceId, 'implements', 'implements')
            );
        }
    }

    private function handleInterface(Stmt\Interface_ $node): void
    {
        $name = $node->name->toString();
        $id   = 'class_' . ($this->currentNamespace ? $this->currentNamespace . '_' : '') . $name;
        $file = $this->collection->getCurrentFile();

        $graphNode = GraphNode::make(
            id: $id,
            label: $name,
            type: 'interface',
            file: $file,
            line: $node->getStartLine(),
            metadata: ['namespace' => $this->currentNamespace ?: null],
            docblock: $node->getDocComment()?->getText(),
        );
        $graphNode->sourcePreview = $this->extractSourcePreview($node);

        $this->collection->addNode($graphNode);

        foreach ($node->extends as $parent) {
            $parentId = 'class_' . $parent->toString();
            $this->collection->addEdge(Edge::make($id, $parentId, 'extends', 'extends'));
        }
    }

    private function handleTrait(Stmt\Trait_ $node): void
    {
        $name = $node->name->toString();
        $id   = 'class_' . ($this->currentNamespace ? $this->currentNamespace . '_' : '') . $name;
        $file = $this->collection->getCurrentFile();

        $graphNode = GraphNode::make(
            id: $id,
            label: $name,
            type: 'trait',
            file: $file,
            line: $node->getStartLine(),
            metadata: ['namespace' => $this->currentNamespace ?: null],
            docblock: $node->getDocComment()?->getText(),
        );
        $graphNode->sourcePreview = $this->extractSourcePreview($node);

        $this->collection->addNode($graphNode);
    }
}
