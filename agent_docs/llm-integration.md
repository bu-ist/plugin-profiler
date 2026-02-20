# LLM Integration

## Strategy: Local-First with API Fallback

The LLM generates natural-language descriptions for each entity in the graph. Descriptions are generated once during analysis and cached in graph-data.json.

## Architecture

    DescriptionGenerator
    +-- OllamaClient      (default: local Ollama container)
    +-- ApiClient          (optional: Gemini Flash, GPT-4o mini, DeepSeek)
        +-- GeminiProvider
        +-- OpenAIProvider
        +-- DeepSeekProvider

Both clients implement LLMClientInterface:

    interface LLMClientInterface
    {
        /**
         * @param array<EntityBatch> $batches
         * @return array<string, string>  // entity_id => description
         */
        public function generateDescriptions(array $batches): array;
    }

## Ollama Client

- Endpoint: http://ollama:11434/api/generate
- Model: configurable, default qwen2.5-coder:7b
- Timeout: 120s per batch (local models are slower)
- The Ollama container auto-pulls the model on first run

## API Client

- Supports OpenAI-compatible API format (works with Gemini, OpenAI, DeepSeek)
- Endpoint: configurable via LLM_API_BASE_URL
- Auth: Bearer token via LLM_API_KEY
- Timeout: 30s per batch

## Prompt Design

### System Prompt (constant across all batches — optimized for API caching)

    You are a WordPress plugin architecture expert. You will receive metadata
    about PHP entities extracted from a WordPress plugin via static analysis.
    
    For each entity, write a clear 2-3 sentence description explaining:
    1. What this entity does
    2. How it fits into the plugin's architecture
    3. Any important side effects, dependencies, or external interactions
    
    Use precise technical language. Reference specific hook names, class
    relationships, and data operations mentioned in the metadata. Do not
    speculate about behavior not evident from the metadata.
    
    Respond with a JSON object mapping entity IDs to descriptions.

### User Prompt (per batch of 25 entities)

    {
      "entities": [
        {
          "id": "class_GFFormsModel",
          "type": "class",
          "label": "GFFormsModel",
          "extends": "GFBaseModel",
          "methods": ["get_form_meta", "get_forms", "save_form"],
          "hooks_registered": ["gform_get_form_filter"],
          "data_operations": ["$wpdb->get_results(rg_form_meta)"],
          "code_snippet": "class GFFormsModel extends GFBaseModel {..."
        }
      ]
    }

### Expected Response

    {
      "class_GFFormsModel": "GFFormsModel is the primary data access layer..."
    }

## Batching Strategy

- Batch size: 25 entities
- Input tokens per batch: ~2,500 (500 system + 80/entity x 25)
- Output tokens per batch: ~3,750 (150/entity x 25)
- For a 250-entity plugin: 10 batches

## Cost Estimates (per Gravity Forms-scale analysis)

- Ollama (local) qwen2.5-coder:7b: $0.00
- Google Gemini 2.5 Flash: ~$0.03
- DeepSeek V3.2-Exp: ~$0.02
- OpenAI GPT-4o mini: ~$0.11

## Error Handling

- If Ollama container is not running -> skip descriptions, log warning
- If API returns error -> retry once with exponential backoff, then skip that batch
- If JSON parse fails on LLM response -> skip descriptions for that batch, log raw response
- Partial descriptions are acceptable — the frontend shows "No description available" for missing entities

## Configuration (Environment Variables)

- LLM_PROVIDER (default: ollama) — ollama, gemini, openai, deepseek
- LLM_MODEL (default: qwen2.5-coder:7b) — Model identifier
- LLM_API_KEY (default: none) — API key for external providers
- LLM_API_BASE_URL (default: none) — Custom API endpoint
- OLLAMA_HOST (default: http://ollama:11434) — Ollama container address
- LLM_BATCH_SIZE (default: 25) — Entities per LLM request
- LLM_TIMEOUT (default: 120) — Timeout in seconds per request
