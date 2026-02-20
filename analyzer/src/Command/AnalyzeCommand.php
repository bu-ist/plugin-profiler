<?php

declare(strict_types=1);

namespace PluginProfiler\Command;

use DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use PluginProfiler\Export\JsonExporter;
use PluginProfiler\Graph\EntityCollection;
use PluginProfiler\Graph\GraphBuilder;
use PluginProfiler\Graph\PluginMetadata;
use PluginProfiler\LLM\ApiClient;
use PluginProfiler\LLM\ClaudeClient;
use PluginProfiler\LLM\DescriptionGenerator;
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
            ->addOption('llm', null, InputOption::VALUE_REQUIRED, 'LLM provider: claude, ollama, openai, gemini, deepseek', 'ollama')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'LLM model name', 'qwen2.5-coder:7b')
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

        // Step 2: Extract plugin metadata from main file
        $mainFile = $scanner->identifyMainPluginFile($allFiles);
        $pluginMeta = $this->extractPluginHeader($mainFile);

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

        // Step 4: Build graph
        $output->writeln('<comment>Building graph...</comment>');
        $meta = new PluginMetadata(
            name: $pluginMeta['name'] ?? basename($pluginPath),
            version: $pluginMeta['version'] ?? '0.0.0',
            description: $pluginMeta['description'] ?? '',
            mainFile: $mainFile !== null ? basename($mainFile) : '',
            totalFiles: count($allFiles),
            totalEntities: $totalEntities,
            analyzedAt: new DateTimeImmutable(),
            hostPath: $hostPath,
        );

        $graph = (new GraphBuilder())->build($collection, $meta);

        // Step 5: LLM descriptions (if requested)
        $noDescriptions = $input->getOption('no-descriptions');
        if (!$noDescriptions) {
            $llmProvider = (string) ($input->getOption('llm') ?? getenv('LLM_PROVIDER') ?: 'ollama');
            $llmModel    = (string) ($input->getOption('model') ?? getenv('LLM_MODEL') ?: 'qwen2.5-coder:7b');
            $apiKey      = (string) ($input->getOption('api-key') ?? getenv('LLM_API_KEY') ?: '');
            $batchSize   = (int) (getenv('LLM_BATCH_SIZE') ?: 25);
            $timeout     = (int) (getenv('LLM_TIMEOUT') ?: 120);

            $output->writeln(sprintf('<comment>Generating descriptions via %s (%s)...</comment>', $llmProvider, $llmModel));

            if ($llmProvider === 'ollama') {
                $ollamaHost = (string) (getenv('OLLAMA_HOST') ?: 'http://ollama:11434');
                $client     = new OllamaClient($ollamaHost, $llmModel, $timeout);
            } elseif ($llmProvider === 'claude') {
                $client = new ClaudeClient($apiKey, $llmModel, min($timeout, 60));
            } else {
                $client = ApiClient::forProvider($llmProvider, $apiKey, $llmModel, min($timeout, 60));
            }

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
        }

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
}
