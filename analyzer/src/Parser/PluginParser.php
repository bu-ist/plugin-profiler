<?php

declare(strict_types=1);

namespace PluginProfiler\Parser;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\ParserFactory;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\BlockJsonVisitor;
use PluginProfiler\Parser\Visitors\JavaScriptVisitor;

class PluginParser
{
    /** @param array<NodeVisitor> $phpVisitors */
    public function __construct(
        private readonly EntityCollection $collection,
        private readonly array $phpVisitors,
        private readonly ?JavaScriptVisitor $jsVisitor = null,
        private readonly ?BlockJsonVisitor $blockJsonVisitor = null,
    ) {}

    /**
     * Parse all PHP files and run them through the registered PHP visitors.
     *
     * @param array<string> $filePaths
     */
    public function parsePhp(array $filePaths): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($filePaths as $filePath) {
            $source = @file_get_contents($filePath);
            if ($source === false) {
                fwrite(STDERR, "Warning: Could not read file: $filePath\n");
                continue;
            }

            $this->collection->setCurrentFile($filePath);
            $this->collection->setCurrentSource($source);

            try {
                $ast = $parser->parse($source);
            } catch (\PhpParser\Error $e) {
                fwrite(STDERR, "Warning: Parse error in $filePath: {$e->getMessage()}\n");
                continue;
            }

            if ($ast === null) {
                continue;
            }

            // Fresh traverser per file to avoid cross-file state leakage in visitors
            $traverser = new NodeTraverser();
            foreach ($this->phpVisitors as $visitor) {
                $traverser->addVisitor($visitor);
            }

            $traverser->traverse($ast);
        }
    }

    /**
     * Parse all JavaScript/TypeScript files using the Peast-based visitor.
     *
     * @param array<string> $filePaths
     */
    public function parseJavaScript(array $filePaths): void
    {
        if ($this->jsVisitor === null) {
            return;
        }

        foreach ($filePaths as $filePath) {
            $source = @file_get_contents($filePath);
            if ($source === false) {
                fwrite(STDERR, "Warning: Could not read file: $filePath\n");
                continue;
            }

            $this->collection->setCurrentFile($filePath);
            $this->collection->setCurrentSource($source);

            $this->jsVisitor->parse($source, $filePath);
        }
    }

    /**
     * Parse all block.json files using the block.json visitor.
     *
     * @param array<string> $filePaths
     */
    public function parseBlockJson(array $filePaths): void
    {
        if ($this->blockJsonVisitor === null) {
            return;
        }

        foreach ($filePaths as $filePath) {
            $this->collection->setCurrentFile($filePath);
            $this->blockJsonVisitor->parse($filePath);
        }
    }
}
