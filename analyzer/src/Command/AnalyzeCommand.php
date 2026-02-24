<?php

declare(strict_types=1);

namespace PluginProfiler\Command;

use DateTimeImmutable;
use PluginProfiler\Export\JsonExporter;
use PluginProfiler\Graph\CrossReferenceResolver;
use PluginProfiler\Graph\CyclicDependencyDetector;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\GraphBuilder;
use PluginProfiler\Graph\PluginMetadata;
use PluginProfiler\Graph\SecurityAnnotator;
use PluginProfiler\LLM\ApiClient;
use PluginProfiler\LLM\ClaudeClient;
use PluginProfiler\LLM\DescriptionGenerator;
use PluginProfiler\LLM\DocblockDescriptionExtractor;
use PluginProfiler\LLM\MetadataDescriptionSynthesizer;
use PluginProfiler\LLM\OllamaClient;
use PluginProfiler\Parser\PluginParser;
use PluginProfiler\Parser\Visitors\BlockJsonVisitor;
use PluginProfiler\Parser\Visitors\ClassVisitor;
use PluginProfiler\Parser\Visitors\DataSourceVisitor;
use PluginProfiler\Parser\Visitors\ExternalInterfaceVisitor;
use PluginProfiler\Parser\Visitors\FileVisitor;
use PluginProfiler\Parser\Visitors\FunctionVisitor;
use PluginProfiler\Parser\Visitors\HookVisitor;
use PluginProfiler\Parser\Visitors\JavaScriptVisitor;
use PluginProfiler\Scanner\FileScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AnalyzeCommand extends Command
{
    protected static $defaultName = 'analyze';

    protected function configure(): void
    {
        $this
            ->setName('analyze')
            ->setDescription('Analyze a WordPress plugin directory and generate a graph visualization')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the WordPress plugin directory')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port for web UI', '9000')
            ->addOption('llm', null, InputOption::VALUE_REQUIRED, 'LLM provider: claude, ollama, openai, gemini')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'LLM model name')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for external LLM provider')
            ->addOption('no-descriptions', null, InputOption::VALUE_NONE, 'Skip LLM description generation')
            ->addOption('json-only', null, InputOption::VALUE_NONE, 'Output JSON only, do not start web server')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory', '/output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pluginPath = $input->getArgument('path');

        if (!is_string($pluginPath) || !is_dir($pluginPath)) {
            $output->writeln(sprintf(
                '<error>Plugin path does not exist or is not a directory: %s</error>',
                $pluginPath
            ));

            return Command::FAILURE;
        }

        $pluginPath = realpath($pluginPath);
        $outputDir  = $input->getOption('output');
        $outputFile = $outputDir . '/graph-data.json';
        $hostPath   = (string) getenv('PLUGIN_PATH');

        $output->writeln(sprintf('<info>Analyzing plugin at: %s</info>', $pluginPath));

        // Step 1: Scan
        $output->writeln('<comment>Scanning files...</comment>');
        $scanner  = new FileScanner();
        $allFiles = $scanner->scan($pluginPath);
        $output->writeln(sprintf('  Found %d files.', count($allFiles)));

        // Step 2: Extract plugin metadata from main file.
        // For WordPress plugins: read the PHP file with "Plugin Name:" header.
        // For WordPress themes: fall back to style.css which carries "Theme Name:".
        $mainFile   = $scanner->identifyMainPluginFile($allFiles, $pluginPath);
        $pluginMeta = $this->extractPluginHeader($mainFile);
        if (empty($pluginMeta)) {
            $pluginMeta = $this->extractThemeHeader($pluginPath);
        }

        // Step 3: Parse
        $output->writeln('<comment>Parsing plugin...</comment>');
        $collection  = new EntityCollection();
        $fileVisitor = new FileVisitor($collection);
        $fileVisitor->setPluginRoot($pluginPath);
        $phpVisitors = [
            new ClassVisitor($collection),
            new FunctionVisitor($collection),
            new HookVisitor($collection),
            new DataSourceVisitor($collection),
            new ExternalInterfaceVisitor($collection),
            $fileVisitor,
        ];

        $parser = new PluginParser(
            $collection,
            $phpVisitors,
            new JavaScriptVisitor($collection),
            new BlockJsonVisitor($collection),
        );

        $blockFiles = $scanner->findBlockJsonFiles($allFiles);
        $jsFiles    = $scanner->findJavaScriptFiles($allFiles);
        $phpFiles   = $scanner->findPhpFiles($allFiles);

        if (!empty($blockFiles)) {
            $output->writeln(sprintf('  Parsing %d block.json file(s)...', count($blockFiles)));
            $parser->parseBlockJson($blockFiles);
        }

        if (!empty($jsFiles)) {
            $output->writeln(sprintf('  Parsing %d JS/TS file(s)...', count($jsFiles)));
            $parser->parseJavaScript($jsFiles);
        }

        $output->writeln(sprintf('  Parsing %d PHP file(s)...', count($phpFiles)));
        $parser->parsePhp($phpFiles);

        $totalEntities = count($collection->getAllNodes());
        $output->writeln(sprintf('  Discovered %d entities.', $totalEntities));

        // Step 4a: Cross-language edge resolution (JS → PHP)
        // Runs before GraphBuilder so the new edges are validated alongside all others.
        (new CrossReferenceResolver())->resolve($collection);

        // Step 4b: Build graph
        $output->writeln('<comment>Building graph...</comment>');
        // Prefer the WordPress "Plugin Name:" header. When absent (non-WP codebase),
        // use the original host-side directory name so the UI shows something
        // meaningful instead of the generic container mount point "/plugin".
        $friendlyName = $pluginMeta['name']
            ?? ($hostPath !== '' ? basename($hostPath) : basename($pluginPath));

        $meta = new PluginMetadata(
            name: $friendlyName,
            version: $pluginMeta['version'] ?? '0.0.0',
            description: $pluginMeta['description'] ?? '',
            mainFile: $mainFile !== null ? basename($mainFile) : '',
            totalFiles: count($allFiles),
            totalEntities: $totalEntities,
            analyzedAt: new DateTimeImmutable(),
            hostPath: $hostPath,
            phpFiles: count($phpFiles),
            jsFiles: count($jsFiles),
        );

        $graph = (new GraphBuilder())->build($collection, $meta);

        // Step 4c: Security annotation — scan function/method bodies for auth,
        // nonce, and sanitization patterns, then propagate to connected endpoints.
        (new SecurityAnnotator())->annotate($graph);

        // Step 4d: Detect circular dependencies along structural edges.
        $graph->cycles = (new CyclicDependencyDetector())->detect($graph);

        // Step 5: LLM descriptions (if requested)
        $noDescriptions = $input->getOption('no-descriptions');
        if (!$noDescriptions) {
            $llmProvider = (string) ($input->getOption('llm') ?? getenv('LLM_PROVIDER') ?: 'ollama');
            $llmModel    = (string) ($input->getOption('model') ?? getenv('LLM_MODEL') ?: 'qwen2.5-coder:7b');
            $apiKey      = (string) ($input->getOption('api-key') ?? getenv('LLM_API_KEY') ?: '');
            $batchSize   = (int) (getenv('LLM_BATCH_SIZE') ?: 25);
            $timeout     = (int) (getenv('LLM_TIMEOUT') ?: 120);

            $output->writeln(sprintf('<comment>Generating descriptions via %s (%s)...</comment>', $llmProvider, $llmModel));

            $client = null;
            if ($llmProvider === 'ollama') {
                $ollamaHost = (string) (getenv('OLLAMA_HOST') ?: 'http://ollama:11434');
                if (!$this->checkOllamaConnectivity($ollamaHost)) {
                    $output->writeln(sprintf('<error>Ollama is not reachable at %s.</error>', $ollamaHost));
                    $output->writeln('<comment>  → Start it: docker compose --profile llm up -d ollama</comment>');
                    $output->writeln('<comment>  → Or skip descriptions: add --no-descriptions</comment>');
                } else {
                    $client = new OllamaClient($ollamaHost, $llmModel, $timeout);
                }
            } elseif ($llmProvider === 'claude') {
                $client = new ClaudeClient($apiKey, $llmModel, $timeout);
            } else {
                $client = ApiClient::forProvider($llmProvider, $apiKey, $llmModel, $timeout);
            }

            if ($client !== null) {
                $totalNodes = count($graph->nodes);
                $generator  = new DescriptionGenerator($client, $batchSize);
                $generator->generate($graph, function (int $done, int $total) use ($output): void {
                    $output->write(sprintf(
                        "\r  Describing entities: %d / %d",
                        $done,
                        $total,
                    ));
                });
                $output->writeln('');
                $output->writeln(sprintf('  Done. %d entities described.', $totalNodes));

                $output->write('  Generating plugin overview…');
                $summary = $generator->generateSummary($graph);
                if ($summary !== null) {
                    $graph->aiSummary = $summary;
                    $output->writeln(' Done.');
                } else {
                    $output->writeln(' Skipped (no descriptions available or LLM unavailable).');
                }
            }
        }

        // Step 5b: Populate descriptions from docblocks for nodes without LLM descriptions.
        // Runs regardless of --no-descriptions so entities with PHPDoc comments
        // always get at least a minimal description in the graph output.
        (new DocblockDescriptionExtractor())->extract($graph);

        // Step 5c: Synthesize descriptions from node metadata for any remaining
        // nodes without descriptions. This ensures --no-descriptions still produces
        // informative context from structural metadata (params, return types, hooks, etc.).
        (new MetadataDescriptionSynthesizer())->synthesize($graph);

        // Step 6: Export
        $output->writeln(sprintf('<comment>Writing output to %s...</comment>', $outputFile));
        (new JsonExporter())->export($graph, $outputFile);

        $output->writeln(sprintf(
            '<info>Done. %d nodes, %d edges written to %s</info>',
            count($graph->nodes),
            count($graph->edges),
            $outputFile,
        ));

        return Command::SUCCESS;
    }

    /**
     * Test whether the Ollama HTTP server is accepting connections.
     *
     * Uses a 3-second timeout so a missing container doesn't stall the pipeline.
     * The /api/tags endpoint is a lightweight, read-only Ollama route that lists
     * installed models and returns HTTP 200 when the server is healthy.
     */
    private function checkOllamaConnectivity(string $ollamaHost): bool
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 3,
                'ignore_errors' => true,
            ],
        ]);

        return @file_get_contents($ollamaHost . '/api/tags', false, $context) !== false;
    }

    /**
     * Extract WordPress plugin header fields from the main plugin file.
     *
     * @return array<string, string>
     */
    private function extractPluginHeader(?string $filePath): array
    {
        if ($filePath === null || !is_readable($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath, false, null, 0, 8192);
        if ($content === false) {
            return [];
        }

        $fields = [];
        $map    = [
            'name'        => 'Plugin Name',
            'version'     => 'Version',
            'description' => 'Description',
        ];

        foreach ($map as $key => $header) {
            if (preg_match('/' . preg_quote($header, '/') . '\s*:\s*(.+)/i', $content, $m)) {
                $fields[$key] = trim($m[1]);
            }
        }

        return $fields;
    }

    /**
     * Extract WordPress theme header fields from style.css in the theme root.
     * Themes use "Theme Name:" rather than "Plugin Name:", but share "Version:"
     * and "Description:" with the plugin format.
     *
     * @return array<string, string>
     */
    private function extractThemeHeader(string $pluginPath): array
    {
        $styleCss = rtrim($pluginPath, '/\\') . DIRECTORY_SEPARATOR . 'style.css';
        if (!is_readable($styleCss)) {
            return [];
        }

        $content = file_get_contents($styleCss, false, null, 0, 8192);
        if ($content === false) {
            return [];
        }

        $fields = [];
        $map    = [
            'name'        => 'Theme Name',
            'version'     => 'Version',
            'description' => 'Description',
        ];

        foreach ($map as $key => $header) {
            if (preg_match('/' . preg_quote($header, '/') . '\s*:\s*(.+)/i', $content, $m)) {
                $fields[$key] = trim($m[1]);
            }
        }

        return $fields;
    }
}
