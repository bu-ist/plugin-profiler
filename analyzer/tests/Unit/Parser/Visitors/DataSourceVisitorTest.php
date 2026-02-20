<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Parser\Visitors;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Parser\Visitors\DataSourceVisitor;

class DataSourceVisitorTest extends TestCase
{
    private EntityCollection $collection;
    private DataSourceVisitor $visitor;

    protected function setUp(): void
    {
        $this->collection = new EntityCollection();
        $this->visitor    = new DataSourceVisitor($this->collection);
    }

    private function parse(string $code): void
    {
        $this->collection->setCurrentFile('/fixture.php');
        $this->collection->setCurrentSource($code);

        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);

        $ast = $parser->parse($code);
        if ($ast !== null) {
            $traverser->traverse($ast);
        }
    }

    public function testEnterNode_WithGetOption_CreatesReadNode(): void
    {
        $this->parse('<?php get_option("my_setting");');

        $this->assertTrue($this->collection->hasNode('data_read_my_setting'));
        $node = $this->collection->getNode('data_read_my_setting');
        $this->assertSame('option', $node?->subtype);
        $this->assertSame('read', $node?->metadata['operation']);
    }

    public function testEnterNode_WithUpdateOption_CreatesWriteNode(): void
    {
        $this->parse('<?php update_option("my_setting", $value);');

        $this->assertTrue($this->collection->hasNode('data_write_my_setting'));
        $node = $this->collection->getNode('data_write_my_setting');
        $this->assertSame('write', $node?->metadata['operation']);
    }

    public function testEnterNode_WithGetPostMeta_CreatesPostMetaNode(): void
    {
        $this->parse('<?php get_post_meta($post_id, "_my_meta_key", true);');

        $this->assertTrue($this->collection->hasNode('data_read__my_meta_key'));
        $node = $this->collection->getNode('data_read__my_meta_key');
        $this->assertSame('post_meta', $node?->subtype);
    }

    public function testEnterNode_WithSetTransient_CreatesTransientNode(): void
    {
        $this->parse('<?php set_transient("my_cache", $data, 3600);');

        $this->assertTrue($this->collection->hasNode('data_write_my_cache'));
        $node = $this->collection->getNode('data_write_my_cache');
        $this->assertSame('transient', $node?->subtype);
    }

    public function testEnterNode_WithWpdbGetResults_CreatesDatabaseNode(): void
    {
        $this->parse('<?php global $wpdb; $wpdb->get_results("SELECT * FROM posts");');

        $nodes = $this->collection->getAllNodes();
        $dbNodes = array_filter($nodes, static fn ($n) => $n->subtype === 'database');
        $this->assertNotEmpty($dbNodes);
    }

    public function testEnterNode_WithWpdbInsert_CreatesWriteNode(): void
    {
        $this->parse('<?php global $wpdb; $wpdb->insert($wpdb->options, ["key" => "val"]);');

        $nodes = $this->collection->getAllNodes();
        $dbNodes = array_filter($nodes, static fn ($n) => $n->subtype === 'database' && $n->metadata['operation'] === 'write');
        $this->assertNotEmpty($dbNodes);
    }

    public function testEnterNode_InsideFunction_CreatesReadsDataEdge(): void
    {
        $this->parse('<?php function my_func() { get_option("some_key"); }');

        $edges = $this->collection->getAllEdges();
        $dataEdges = array_filter($edges, static fn ($e) => $e->type === 'reads_data');
        $this->assertNotEmpty($dataEdges);

        $edge = reset($dataEdges);
        $this->assertSame('func_my_func', $edge->source);
    }

    public function testEnterNode_InsideMethod_CreatesWritesDataEdge(): void
    {
        $this->parse('<?php class Foo { public function save() { update_option("key", "val"); } }');

        $edges = $this->collection->getAllEdges();
        $writeEdges = array_filter($edges, static fn ($e) => $e->type === 'writes_data');
        $this->assertNotEmpty($writeEdges);
    }
}
