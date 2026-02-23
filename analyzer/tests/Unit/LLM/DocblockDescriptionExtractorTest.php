<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\LLM;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;
use PluginProfiler\Graph\PluginMetadata;
use PluginProfiler\LLM\DocblockDescriptionExtractor;

class DocblockDescriptionExtractorTest extends TestCase
{
    private DocblockDescriptionExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new DocblockDescriptionExtractor();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeNode(string $id, ?string $docblock, ?string $description = null): Node
    {
        $node = Node::make(
            id: $id,
            label: $id,
            type: 'class',
            file: '/plugin/src/Foo.php',
            docblock: $docblock,
        );
        if ($description !== null) {
            $node->description = $description;
        }

        return $node;
    }

    private function buildGraph(array $nodes): Graph
    {
        return new Graph(
            nodes: $nodes,
            edges: [],
            plugin: new PluginMetadata(
                name: 'Test',
                version: '1.0',
                description: '',
                mainFile: 'test.php',
                totalFiles: 1,
                totalEntities: count($nodes),
                analyzedAt: new DateTimeImmutable(),
                analyzerVersion: '1.0',
                hostPath: '',
                phpFiles: 1,
                jsFiles: 0,
            ),
        );
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function testExtract_WithSingleLineSummary_SetsDescription(): void
    {
        $node  = $this->makeNode('class_Foo', "/**\n * Manages user sessions.\n */");
        $graph = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertSame('Manages user sessions.', $node->description);
    }

    public function testExtract_WithMultiLineSummary_JoinsLines(): void
    {
        $docblock = "/**\n * Handles HTTP requests\n * and routes them to controllers.\n */";
        $node     = $this->makeNode('class_Foo', $docblock);
        $graph    = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertSame('Handles HTTP requests and routes them to controllers.', $node->description);
    }

    public function testExtract_StopsAtAtTag_ReturnsOnlySummaryParagraph(): void
    {
        $docblock = "/**\n * Sanitises and saves post data.\n *\n * @param array \$data\n * @return bool\n */";
        $node     = $this->makeNode('class_Foo', $docblock);
        $graph    = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertSame('Sanitises and saves post data.', $node->description);
    }

    public function testExtract_StopsAtBlankLine_ReturnsFirstParagraphOnly(): void
    {
        $docblock = "/**\n * Short summary.\n *\n * Longer description that should be excluded.\n */";
        $node     = $this->makeNode('class_Foo', $docblock);
        $graph    = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertSame('Short summary.', $node->description);
    }

    public function testExtract_NodeAlreadyHasDescription_SkipsNode(): void
    {
        $node  = $this->makeNode('class_Foo', "/**\n * Docblock summary.\n */", 'Existing description.');
        $graph = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertSame('Existing description.', $node->description);
    }

    public function testExtract_NodeHasNoDocblock_LeavesDescriptionNull(): void
    {
        $node  = $this->makeNode('class_Foo', null);
        $graph = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertNull($node->description);
    }

    public function testExtract_PlainTextDocblock_ReturnsFirstLine(): void
    {
        // block.json descriptions are stored as plain text, not PHPDoc
        $node  = $this->makeNode('block_my_block', 'A block that displays recent posts.');
        $graph = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertSame('A block that displays recent posts.', $node->description);
    }

    public function testExtract_EmptyDocblock_LeavesDescriptionNull(): void
    {
        $node  = $this->makeNode('class_Foo', "/**\n */");
        $graph = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertNull($node->description);
    }

    public function testExtract_DocblockWithOnlyAtTags_LeavesDescriptionNull(): void
    {
        $docblock = "/**\n * @param string \$foo\n * @return void\n */";
        $node     = $this->makeNode('class_Foo', $docblock);
        $graph    = $this->buildGraph([$node]);

        $this->extractor->extract($graph);

        $this->assertNull($node->description);
    }

    public function testExtract_MultipleNodes_PopulatesAll(): void
    {
        $nodeA = $this->makeNode('class_A', "/**\n * Description A.\n */");
        $nodeB = $this->makeNode('class_B', "/**\n * Description B.\n */");
        $graph = $this->buildGraph([$nodeA, $nodeB]);

        $this->extractor->extract($graph);

        $this->assertSame('Description A.', $nodeA->description);
        $this->assertSame('Description B.', $nodeB->description);
    }
}
