# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Project Is

A Dockerized static analysis tool that parses a WordPress plugin directory and produces an interactive Cytoscape.js graph of its architecture — classes, hooks, data sources, endpoints — with AI-generated entity descriptions. Target users are WordPress developers auditing or refactoring plugin codebases.

**Current state**: Design phase. The `agent_docs/` directory contains full architecture specs; no implementation code exists yet. See `agent_docs/implementation-plan.md` for the phased build plan.

## Commands

### PHP Analyzer (from `analyzer/`)
    composer install
    ./vendor/bin/phpunit
    ./vendor/bin/phpunit --filter=HookVisitor       # single test class
    ./vendor/bin/php-cs-fixer fix --dry-run          # lint check
    ./vendor/bin/php-cs-fixer fix                    # auto-fix

### Frontend (from `web/`)
    npm install
    npm run build
    npm run dev

### Docker (from project root)
    docker compose build
    docker compose up
    docker compose run analyzer php analyze /plugin
    docker compose down

### Linting
    cd analyzer && ./vendor/bin/php-cs-fixer fix --dry-run
    cd web && npx eslint js/

## Architecture Overview

Three Docker containers: **analyzer** (PHP 8.1 CLI), **web** (nginx), **ollama** (optional LLM). The analyzer writes `graph-data.json` to a shared volume; the web container serves it with a Cytoscape.js frontend.

### Analysis Pipeline

The PHP analyzer runs this pipeline sequentially:

1. **FileScanner** — Recursively discovers `.php` files, identifies the main plugin file by its `Plugin Name:` header. Skips `vendor/`, `node_modules/`, `.git/`.
2. **PluginParser** — Parses each file into an AST via nikic/php-parser v5, then runs a NodeTraverser with all Visitors. Visitors write to a shared `EntityCollection`.
3. **GraphBuilder** — Resolves cross-references (e.g., callback strings → function nodes), deduplicates, validates all edge targets exist.
4. **DescriptionGenerator** — Batches entities (25/request) to Ollama or an external LLM API, attaches descriptions to nodes.
5. **JsonExporter** — Writes Cytoscape.js-compatible JSON to `/output/graph-data.json`.

### Visitor Pattern (Key Design Decision)

Each Visitor handles one concern, extends `PhpParser\NodeVisitorAbstract`, and writes to the shared `EntityCollection`. Six visitors planned:

| Visitor | Detects |
|---------|---------|
| ClassVisitor | Classes, interfaces, traits + inheritance edges |
| FunctionVisitor | Standalone functions, class methods + `defines` edges |
| HookVisitor | `add_action`/`add_filter`/`do_action`/`apply_filters` + callback resolution |
| DataSourceVisitor | Options, post_meta, user_meta, transients, `$wpdb` calls + read/write edges |
| ExternalInterfaceVisitor | REST routes, AJAX, shortcodes, admin pages, cron, post types, taxonomies, HTTP calls |
| FileVisitor | `include`/`require` statements + file-to-file edges |

### Node ID Convention

Deterministic IDs prevent duplicates across visitors:
- `class_{namespace}_{name}`, `method_{class}_{name}`, `func_{name}`
- `hook_{type}_{name}`, `data_{operation}_{key}`, `rest_{method}_{route}`
- `file_{relative_path}`

### Frontend

Vanilla JS (no build framework): Cytoscape.js for the graph, Alpine.js for reactivity, Tailwind CSS via CDN, Prism.js for syntax highlighting. Files: `app.js`, `graph.js`, `sidebar.js`, `search.js`, `layouts.js`.

## Code Conventions

- **PHP**: PSR-12, `declare(strict_types=1)` in every file, PHP 8.1+ features (readonly, enums, constructor promotion, named args)
- **JS**: ES modules, vanilla + Alpine.js, 2-space indent
- **Tests**: One test class per Visitor, name pattern `test{Method}_{Scenario}_{Expected}`, Arrange/Act/Assert
- **Commits**: Conventional Commits (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`, `chore:`)
- **Docker**: Alpine variants only for all images
- **Dependencies**: Never add without updating `composer.json` or `package.json`

## Verification

After any code change:
1. `cd analyzer && ./vendor/bin/phpunit` — all tests pass
2. `cd analyzer && ./vendor/bin/php-cs-fixer fix --dry-run` — no style violations
3. `docker compose build` — containers build successfully

## Detailed Design Docs

The `agent_docs/` directory contains full specifications. Read the relevant file before implementing that component:
- **architecture.md** — Container specs, shared volumes, CLI entry point and options
- **analysis-engine.md** — AST pipeline, each Visitor's detection logic, EntityCollection API
- **graph-schema.md** — JSON format, all node types/subtypes, all edge types, validation rules
- **visualization.md** — Cytoscape.js config, node styling by type, sidebar inspector, search/filter
- **llm-integration.md** — Ollama + API client interface, prompt templates, batching strategy
- **implementation-plan.md** — 5-phase build plan with task checklists
