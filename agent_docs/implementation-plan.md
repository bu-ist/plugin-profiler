# Implementation Plan

## Phase 1: Foundation (Week 1)

### Task 1.1: Project Scaffold
- [ ] Create directory structure per CLAUDE.md
- [ ] Initialize analyzer/composer.json with dependencies:
  - nikic/php-parser: ^5.0
  - symfony/console: ^7.0
  - phpunit/phpunit: ^10.0 (dev)
  - friendsofphp/php-cs-fixer: ^3.0 (dev)
- [ ] Initialize web/package.json (minimal: dev server only)
- [ ] Create docker-compose.yml with all 3 services
- [ ] Create Dockerfile.analyzer (php:8.1-cli-alpine + Composer)
- [ ] Create Dockerfile.web (multi-stage: node build then nginx serve)
- [ ] Create .env.example
- [ ] Create bin/plugin-profiler shell script (basic: validate args + docker compose up)
- [ ] Verify: docker compose build succeeds

### Task 1.2: FileScanner
- [ ] Implement Scanner/FileScanner.php
  - scan(string $dir): array<SplFileInfo> — recursively find all .php files
  - identifyMainPluginFile(array $files): ?SplFileInfo — find file with Plugin Name: header
  - Skip vendor/, node_modules/, .git/ directories
- [ ] Write tests: tests/Unit/Scanner/FileScannerTest.php
  - Test with fixture plugin in tests/fixtures/sample-plugin/
- [ ] Verify: tests pass

### Task 1.3: ClassVisitor + Basic Pipeline
- [ ] Implement Graph/Node.php and Graph/Edge.php data classes
- [ ] Implement Graph/EntityCollection.php
- [ ] Implement Parser/PluginParser.php (orchestrates parsing + traversal)
- [ ] Implement Parser/Visitors/ClassVisitor.php
- [ ] Write tests: tests/Unit/Parser/Visitors/ClassVisitorTest.php
  - Test: detects class, extracts name/namespace/extends/implements
  - Test: handles interface and trait
  - Test: extracts docblock
- [ ] Verify: parse a fixture file then get Node objects

## Phase 2: Visitors (Week 2)

### Task 2.1: FunctionVisitor
- [ ] Implement Parser/Visitors/FunctionVisitor.php
- [ ] Tests for: standalone functions, class methods, visibility, params, return types
- [ ] Verify: edges connect methods to parent classes

### Task 2.2: HookVisitor
- [ ] Implement Parser/Visitors/HookVisitor.php
- [ ] Tests for: add_action, add_filter, do_action, apply_filters
- [ ] Tests for callback types: string, array, closure
- [ ] Tests for priority extraction
- [ ] Verify: hook nodes created, edges connect callers to hooks

### Task 2.3: DataSourceVisitor
- [ ] Implement Parser/Visitors/DataSourceVisitor.php
- [ ] Tests for: options, post_meta, transients, $wpdb calls
- [ ] Tests for: read vs write operation detection
- [ ] Verify: data source nodes + edges

### Task 2.4: ExternalInterfaceVisitor
- [ ] Implement Parser/Visitors/ExternalInterfaceVisitor.php
- [ ] Tests for: REST routes, AJAX handlers, shortcodes, admin pages, cron, post types, taxonomies, HTTP calls
- [ ] Verify: external interface nodes + edges

### Task 2.5: FileVisitor
- [ ] Implement Parser/Visitors/FileVisitor.php
- [ ] Tests for: include, require, include_once, require_once
- [ ] Verify: file-to-file edges

### Task 2.6: GraphBuilder + JSON Export
- [ ] Implement Graph/GraphBuilder.php
  - Cross-reference resolution (callback strings to function nodes)
  - Deduplication
  - Validation (all edge targets exist)
- [ ] Implement JSON export matching agent_docs/graph-schema.md
- [ ] Integration test: parse fixture plugin then validate full JSON output
- [ ] Verify: docker compose run analyzer produces valid graph-data.json

## Phase 3: Frontend (Week 3)

### Task 3.1: Graph Rendering
- [ ] Create web/index.html with layout structure (toolbar + graph canvas + sidebar)
- [ ] Implement web/js/graph.js — Cytoscape.js init, node/edge styling per visualization.md
- [ ] Implement web/js/app.js — fetch graph-data.json, initialize graph
- [ ] Implement web/js/layouts.js — layout configs, switcher
- [ ] Load real JSON from analyzer output
- [ ] Verify: graph renders in browser, nodes are colored by type

### Task 3.2: Sidebar Inspector
- [ ] Implement web/js/sidebar.js
- [ ] Click handler: open sidebar, populate with node data
- [ ] Render: name, type badge, description, file:line, connections, source preview
- [ ] Integrate Prism.js for PHP syntax highlighting
- [ ] VS Code link: vscode://file/... URI
- [ ] Verify: click any node then see full detail panel

### Task 3.3: Search, Filter, Polish
- [ ] Implement web/js/search.js — search input with autocomplete
- [ ] Type filter toggles (toolbar buttons)
- [ ] Hover highlighting (dim unconnected nodes)
- [ ] Double-click zoom-to-neighborhood
- [ ] Keyboard shortcuts (Esc, /, F)
- [ ] Responsive layout, dark/light theme
- [ ] Verify: full interaction model works

## Phase 4: LLM Integration (Week 4)

### Task 4.1: Ollama Client
- [ ] Implement LLM/OllamaClient.php (implements LLMClientInterface)
- [ ] HTTP client: send prompt to Ollama API, parse JSON response
- [ ] Test with mock HTTP responses

### Task 4.2: API Client
- [ ] Implement LLM/ApiClient.php (OpenAI-compatible format)
- [ ] Provider detection from env vars
- [ ] Test with mock HTTP responses

### Task 4.3: DescriptionGenerator
- [ ] Implement LLM/DescriptionGenerator.php
  - Batch entities into groups of 25
  - Build system prompt + user prompts per batch
  - Call LLM client
  - Parse response, attach descriptions to nodes
  - Handle partial failures gracefully
- [ ] Integration test: mock LLM then verify descriptions in output JSON

### Task 4.4: End-to-End Integration
- [ ] Wire AnalyzeCommand: FileScanner then PluginParser then GraphBuilder then DescriptionGenerator then JsonExporter
- [ ] CLI option parsing (--llm, --model, --api-key, --no-descriptions, --json-only)
- [ ] bin/plugin-profiler shell script: full workflow
- [ ] Test against a real WordPress plugin (use Hello Dolly or a small open-source plugin)
- [ ] Verify: full pipeline from CLI command then browser visualization

## Phase 5: QA and Docs (Week 5)

### Task 5.1: Test against Gravity Forms-scale plugin
- [ ] Run against a large plugin (~300+ files)
- [ ] Performance profiling: time each phase
- [ ] Fix any memory issues or timeout problems
- [ ] Verify: renders in browser within performance targets

### Task 5.2: README + Documentation
- [ ] Write comprehensive README.md
- [ ] Usage examples, screenshots
- [ ] Docker requirements section
- [ ] Contributing guide

### Task 5.3: CI/CD
- [ ] GitHub Actions: run PHPUnit, php-cs-fixer, eslint
- [ ] Docker build test
