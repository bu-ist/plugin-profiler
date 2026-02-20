# System Architecture

## Overview

Plugin Profiler is a 3-container Docker Compose application. The user invokes a shell script (bin/plugin-profiler) which orchestrates docker compose with the right volume mounts and environment variables.

## Data Flow

    User plugin dir (read-only mount)
            |
            v
    +---------------------+
    |  analyzer container  |
    |  (php:8.1-cli-alpine)|
    |                      |
    |  FileScanner         |--> discovers PHP files, identifies main plugin file
    |  PluginParser        |--> parses each file into AST via nikic/php-parser
    |  NodeVisitors        |--> extract entities (classes, hooks, data sources, etc.)
    |  GraphBuilder        |--> assembles nodes[] + edges[] into graph model
    |  DescriptionGenerator|--> sends entity metadata to LLM, receives descriptions
    |                      |
    |  Output: /output/graph-data.json
    +----------+-----------+
               | shared volume
               v
    +---------------------+
    |  web container       |
    |  (nginx:alpine)      |
    |  Serves:             |
    |  - index.html        |
    |  - JS bundle         |
    |  - graph-data.json   |
    |  Exposes: :9000      |--> User browser
    +---------------------+
    
    +---------------------+
    |  ollama container    |  (optional, profile: llm)
    |  (ollama/ollama)     |
    |  Model: qwen2.5-     |
    |    coder:7b          |
    |  API: :11434         |--> analyzer container calls via HTTP
    +---------------------+

## Container Specifications

### analyzer
- Image: php:8.1-cli-alpine
- Volumes: ${PLUGIN_PATH}:/plugin:ro, output:/output
- Depends on: ollama (optional)
- Entrypoint: php /app/bin/analyze
- Environment: LLM_PROVIDER, LLM_MODEL, API_KEY, OLLAMA_HOST

### web
- Image: multi-stage build: node:20-alpine (build) then nginx:alpine (serve)
- Volumes: output:/usr/share/nginx/html/ro
- Ports: ${PORT:-9000}:80
- Depends on: analyzer

### ollama
- Image: ollama/ollama
- Volumes: ollama_models:/root/.ollama
- Ports: 11434:11434
- Profiles: ["llm"] — only starts when --profile llm is passed

## Shared Volumes

- output: graph-data.json transfer from analyzer to web. Mounted in analyzer (rw), web (ro)
- ollama_models: Persist downloaded LLM models across runs. Mounted in ollama.

## CLI Entry Point (bin/plugin-profiler)

Shell script that:
1. Validates plugin path argument exists and is a directory
2. Exports PLUGIN_PATH as absolute path
3. Runs docker compose --profile llm up --build (or without --profile llm if --no-descriptions)
4. Waits for analyzer to exit
5. Opens browser to http://localhost:${PORT:-9000}

### CLI Options
    plugin-profiler analyze <path> [options]
      --port <n>              Port for web UI (default: 9000)
      --llm <provider>        claude | ollama | openai | gemini (default: ollama)
      --model <name>          LLM model name (default: qwen2.5-coder:7b)
      --api-key <key>         API key for external LLM provider
      --no-descriptions       Skip LLM description generation
      --json-only             Output JSON only, don't start web server
      --output <dir>          Output directory (default: ./output)
