<?php

declare(strict_types=1);

namespace PluginProfiler\Tests\Unit\Graph;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\GraphBuilder;
use PluginProfiler\Graph\Node;
use PluginProfiler\Graph\PluginMetadata;

class GraphBuilderTest extends TestCase
{
    private GraphBuilder $builder;
    private PluginMetadata $meta;

    protected function setUp(): void
    {
        $this->builder = new GraphBuilder();
        $this->meta    = new PluginMetadata(
            name: 'Test Plugin',
            version: '1.0.0',
            description: '',
            mainFile: 'test.php',
            totalFiles: 1,
            totalEntities: 0,
            analyzedAt: new DateTimeImmutable(),
        );
    }

    public function testBuild_WithValidEdge_PreservesEdge(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'node_a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addNode(Node::make(id: 'node_b', label: 'B', type: 'class', file: 'b.php'));
        $collection->addEdge(Edge::make('node_a', 'node_b', 'extends', 'extends'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(1, $graph->edges);
    }

    public function testBuild_WithOrphanEdgeSourceMissing_DropsEdge(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'node_b', label: 'B', type: 'class', file: 'b.php'));
        $collection->addEdge(Edge::make('nonexistent_source', 'node_b', 'extends', 'extends'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(0, $graph->edges);
    }

    public function testBuild_WithOrphanEdgeTargetMissing_DropsEdge(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'node_a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addEdge(Edge::make('node_a', 'nonexistent_target', 'extends', 'extends'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(0, $graph->edges);
    }

    public function testBuild_EdgeIds_AreSequentialStrings(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addNode(Node::make(id: 'b', label: 'B', type: 'class', file: 'b.php'));
        $collection->addNode(Node::make(id: 'c', label: 'C', type: 'class', file: 'c.php'));
        $collection->addEdge(Edge::make('a', 'b', 'extends', 'extends'));
        $collection->addEdge(Edge::make('b', 'c', 'implements', 'implements'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertSame('e_0', $graph->edges[0]->id);
        $this->assertSame('e_1', $graph->edges[1]->id);
    }

    public function testBuild_AllNodes_ArePreserved(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addNode(Node::make(id: 'b', label: 'B', type: 'function', file: 'b.php'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(2, $graph->nodes);
    }

    public function testBuild_PluginMetadata_IsPassedThrough(): void
    {
        $collection = new EntityCollection();
        $graph      = $this->builder->build($collection, $this->meta);

        $this->assertSame('Test Plugin', $graph->plugin->name);
        $this->assertSame('1.0.0', $graph->plugin->version);
    }

    public function testBuild_WithMixedEdges_OnlyValidEdgesKept(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: 'a.php'));
        $collection->addNode(Node::make(id: 'b', label: 'B', type: 'class', file: 'b.php'));

        $collection->addEdge(Edge::make('a', 'b', 'extends', 'extends'));          // valid
        $collection->addEdge(Edge::make('a', 'missing', 'extends', 'extends'));    // invalid target
        $collection->addEdge(Edge::make('missing', 'b', 'extends', 'extends'));    // invalid source

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertCount(1, $graph->edges);
        $this->assertSame('a', $graph->edges[0]->source);
        $this->assertSame('b', $graph->edges[0]->target);
    }

    public function testBuild_EmptyCollection_ReturnsEmptyGraph(): void
    {
        $collection = new EntityCollection();
        $graph      = $this->builder->build($collection, $this->meta);

        $this->assertCount(0, $graph->nodes);
        $this->assertCount(0, $graph->edges);
    }

    // ── Library detection ─────────────────────────────────────────────────────

    public function testBuild_NodeInLibDir_IsNotTaggedIsLibrary(): void
    {
        // lib/ is commonly used for developer-written utilities in WordPress plugins
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/lib/Calendar.php'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertFalse($graph->nodes[0]->isLibrary);
    }

    public function testBuild_NodeInVendorSubdir_IsTaggedIsLibrary(): void
    {
        // js/vendor/ and assets/vendor/ are bundled third-party libraries
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/js/vendor/jquery.validate.js'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertTrue($graph->nodes[0]->isLibrary);
    }

    public function testBuild_JsFileWithLibraryFilenamePrefix_IsTaggedIsLibrary(): void
    {
        // jquery.js in any directory is detected via the filename prefix list
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/assets/js/jquery.js'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertTrue($graph->nodes[0]->isLibrary);
    }

    public function testBuild_JsFileWithVersionedLibraryFilename_IsTaggedIsLibrary(): void
    {
        // jquery-3.6.0.js — versioned library filename
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/assets/jquery-3.6.0.js'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertTrue($graph->nodes[0]->isLibrary);
    }

    public function testBuild_NodeInThirdPartyDir_IsTaggedIsLibrary(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/third-party/foo.php'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertTrue($graph->nodes[0]->isLibrary);
    }

    public function testBuild_NodeInDeveloperDir_IsNotTaggedIsLibrary(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/includes/class-plugin.php'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertFalse($graph->nodes[0]->isLibrary);
    }

    public function testBuild_NodeWithLibInFilename_IsNotTaggedIsLibrary(): void
    {
        // "lib" in a filename (not a directory segment) should not trigger the flag
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/src/library-loader.php'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertFalse($graph->nodes[0]->isLibrary);
    }

    public function testBuild_PhpFileWithJqueryLikeName_IsNotTaggedIsLibrary(): void
    {
        // Filename prefix detection only applies to .js files, not .php
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/src/jquery-helpers.php'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertFalse($graph->nodes[0]->isLibrary);
    }

    public function testBuild_ReactScaffoldFile_IsTaggedIsLibrary(): void
    {
        // CRA boilerplate like reportWebVitals.js is scaffold, not developer code
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'function', file: '/plugin/src/reportWebVitals.js'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertTrue($graph->nodes[0]->isLibrary);
    }

    public function testBuild_SetupTestsFile_IsTaggedIsLibrary(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'function', file: '/plugin/src/setupTests.js'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertTrue($graph->nodes[0]->isLibrary);
    }

    public function testBuild_PHPMailerFile_IsTaggedIsLibrary(): void
    {
        // Bundled PHPMailer — well-known PHP library bundled inside plugins
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'class', file: '/plugin/includes/class.phpmailer.php'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertTrue($graph->nodes[0]->isLibrary);
    }

    public function testBuild_ServiceWorkerFile_IsTaggedIsLibrary(): void
    {
        $collection = new EntityCollection();
        $collection->addNode(Node::make(id: 'a', label: 'A', type: 'function', file: '/plugin/public/serviceWorker.js'));

        $graph = $this->builder->build($collection, $this->meta);

        $this->assertTrue($graph->nodes[0]->isLibrary);
    }
}
