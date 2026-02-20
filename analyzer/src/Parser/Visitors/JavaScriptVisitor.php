<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use Peast\Peast;
use Peast\Syntax\Node\CallExpression;
use Peast\Syntax\Node\ImportDeclaration;
use Peast\Syntax\Node\MemberExpression;
use Peast\Syntax\Node\StringLiteral;
use Peast\Syntax\Node\Node as PeastNode;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\Node as GraphNode;

class JavaScriptVisitor
{
    public function __construct(
        private readonly EntityCollection $collection,
    ) {}

    /**
     * Parse a JavaScript/TypeScript source string and populate the entity collection.
     */
    public function parse(string $source, string $filePath): void
    {
        // Try ES module mode first, fall back to script mode
        try {
            $ast = Peast::latest($source, [
                'sourceType' => Peast::SOURCE_TYPE_MODULE,
                'jsx'        => true,
            ])->parse();
        } catch (\Exception $e) {
            try {
                $ast = Peast::latest($source, [
                    'sourceType' => Peast::SOURCE_TYPE_SCRIPT,
                    'jsx'        => true,
                ])->parse();
            } catch (\Exception $e2) {
                fwrite(STDERR, "Warning: JS parse error in $filePath: {$e2->getMessage()}\n");

                return;
            }
        }

        $self = $this;

        $ast->traverse(function (PeastNode $node) use ($self, $filePath): void {
            $type = $node->getType();

            if ($type === 'CallExpression') {
                /** @var CallExpression $node */
                $self->handleCallExpression($node, $filePath);
            } elseif ($type === 'ImportDeclaration') {
                /** @var ImportDeclaration $node */
                $self->handleImport($node, $filePath);
            }
        });
    }

    private function handleCallExpression(CallExpression $node, string $filePath): void
    {
        $callee = $node->getCallee();
        if ($callee === null) {
            return;
        }

        // Detect the function/method name
        $callName = $this->resolveCalleeName($callee);
        if ($callName === null) {
            return;
        }

        match ($callName) {
            'registerBlockType'                   => $this->handleRegisterBlockType($node, $filePath),
            'addAction', 'wp.hooks.addAction'     => $this->handleJsHook($node, $filePath, 'action'),
            'addFilter', 'wp.hooks.addFilter'     => $this->handleJsHook($node, $filePath, 'filter'),
            'apiFetch'                            => $this->handleApiFetch($node, $filePath),
            default                               => null,
        };
    }

    private function handleRegisterBlockType(CallExpression $node, string $filePath): void
    {
        $args = $node->getArguments();
        if (empty($args)) {
            return;
        }

        $firstArg = $args[0];
        if (!$firstArg instanceof StringLiteral) {
            return;
        }

        $blockName = $firstArg->getValue();
        $nodeId    = 'block_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $blockName);

        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $blockName,
            type: 'gutenberg_block',
            file: $filePath,
            line: $node->getLocation()?->getStart()?->getLine() ?? 0,
            metadata: ['block_name' => $blockName],
        ));

        $this->addFileEdge($filePath, $nodeId, 'registers_block', 'registers');
    }

    private function handleJsHook(CallExpression $node, string $filePath, string $hookType): void
    {
        $args = $node->getArguments();
        if (empty($args)) {
            return;
        }

        $firstArg = $args[0];
        if (!$firstArg instanceof StringLiteral) {
            // Dynamic hook name — skip
            return;
        }

        $hookName = $firstArg->getValue();
        $nodeId   = 'js_hook_' . $hookType . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $hookName);

        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $hookName,
            type: 'js_hook',
            file: $filePath,
            line: $node->getLocation()?->getStart()?->getLine() ?? 0,
            subtype: $hookType,
            metadata: ['hook_name' => $hookName],
        ));

        $this->addFileEdge($filePath, $nodeId, 'js_registers_hook', 'registers');
    }

    private function handleApiFetch(CallExpression $node, string $filePath): void
    {
        $args = $node->getArguments();
        if (empty($args)) {
            return;
        }

        // apiFetch({ path: '/wp/v2/posts', method: 'GET' })
        $configArg = $args[0];
        if ($configArg->getType() !== 'ObjectExpression') {
            return;
        }

        $path   = null;
        $method = 'GET';

        foreach ($configArg->getProperties() as $prop) {
            if ($prop->getType() !== 'Property') {
                continue;
            }

            $key = $prop->getKey();
            if ($key === null) {
                continue;
            }

            $keyName  = $this->resolvePropertyKeyName($key);
            $propVal  = $prop->getValue();

            if ($keyName === 'path' && $propVal instanceof StringLiteral) {
                $path = $propVal->getValue();
            }
            if ($keyName === 'method' && $propVal instanceof StringLiteral) {
                $method = strtoupper($propVal->getValue());
            }
        }

        if ($path === null) {
            return;
        }

        $sanitizedPath = preg_replace('/[^a-zA-Z0-9_\-\/]/', '_', $path);
        $nodeId        = 'js_api_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $method . '_' . $sanitizedPath);

        $this->collection->addNode(GraphNode::make(
            id: $nodeId,
            label: $method . ' ' . $path,
            type: 'js_api_call',
            file: $filePath,
            line: $node->getLocation()?->getStart()?->getLine() ?? 0,
            metadata: [
                'http_method' => $method,
                'route'       => $path,
            ],
        ));

        $this->addFileEdge($filePath, $nodeId, 'js_api_call', 'calls');
    }

    /**
     * Ensure a file node exists and add an edge from it to a target node.
     */
    private function addFileEdge(string $filePath, string $targetId, string $type, string $label): void
    {
        $fileNodeId = GraphNode::sanitizeId('file_' . $filePath);

        if (!$this->collection->hasNode($fileNodeId)) {
            $this->collection->addNode(GraphNode::make(
                id: $fileNodeId,
                label: basename($filePath),
                type: 'file',
                file: $filePath,
                line: 0,
            ));
        }

        $this->collection->addEdge(Edge::make($fileNodeId, $targetId, $type, $label));
    }

    private function handleImport(ImportDeclaration $node, string $filePath): void
    {
        $sourceNode = $node->getSource();
        if (!$sourceNode instanceof StringLiteral) {
            return;
        }

        // Only track WordPress package imports for now
        $importPath = $sourceNode->getValue();
        if (!str_starts_with($importPath, '@wordpress/')) {
            return;
        }

        // These are informational — tracked in metadata, no separate node needed
        // Future: could create a "wp_package_dependency" node type
    }

    /**
     * Resolve a callee expression to a flat name string.
     * Handles: Identifier ('addAction'), MemberExpression ('wp.hooks.addAction').
     */
    private function resolveCalleeName(PeastNode $callee): ?string
    {
        $type = $callee->getType();

        if ($type === 'Identifier') {
            return $callee->getName();
        }

        if ($type === 'MemberExpression') {
            /** @var MemberExpression $callee */
            $property = $callee->getProperty();
            if ($property === null) {
                return null;
            }

            $propName = $property->getType() === 'Identifier' ? $property->getName() : null;

            // Build full dotted name from any MemberExpression or Identifier chain
            $object = $callee->getObject();
            if ($object !== null) {
                $objectName = $this->resolveCalleeName($object);
                if ($objectName !== null && $propName !== null) {
                    return $objectName . '.' . $propName;
                }
            }

            return $propName;
        }

        return null;
    }

    private function resolvePropertyKeyName(PeastNode $key): ?string
    {
        if ($key->getType() === 'Identifier') {
            return $key->getName();
        }
        if ($key instanceof StringLiteral) {
            return $key->getValue();
        }

        return null;
    }
}
