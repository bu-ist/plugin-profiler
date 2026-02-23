<?php

declare(strict_types=1);

namespace PluginProfiler\LLM;

/**
 * Ollama client using the /api/chat endpoint.
 *
 * Uses the chat endpoint rather than the legacy /api/generate so that the
 * system prompt and user message are sent as distinct roles. Instruction-tuned
 * models (e.g. qwen2.5-coder) follow role-separated prompts significantly
 * better than a single concatenated string.
 *
 * @see https://github.com/ollama/ollama/blob/main/docs/api.md#generate-a-chat-completion
 */
class OllamaClient implements LLMClientInterface
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a WordPress plugin architecture expert. You will receive metadata
about PHP and JavaScript entities extracted from a WordPress plugin via static analysis.

For each entity, write a clear 1-2 sentence description explaining what it does
and how it fits into the plugin's architecture. Be concise and precise.

IMPORTANT: Respond ONLY with a valid JSON object. The format must be:
{"entity_id_1": "Description here.", "entity_id_2": "Description here."}

Use the exact entity IDs provided in the input as the JSON keys.
Do not wrap the JSON in markdown code fences or any other text.
PROMPT;

    public function __construct(
        private readonly string $ollamaHost,
        private readonly string $model,
        private readonly int $timeout = 300,
    ) {
    }

    public function generateText(string $prompt): ?string
    {
        $payload = json_encode([
            'model'    => $this->model,
            'stream'   => false,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $responseRaw = @file_get_contents($this->ollamaHost . '/api/chat', false, $context);
        if ($responseRaw === false) {
            return null;
        }

        $response = json_decode($responseRaw, true);

        return is_array($response) ? ($response['message']['content'] ?? null) : null;
    }

    public function generateDescriptions(array $entityBatch): array
    {
        $payload = json_encode([
            'model'    => $this->model,
            'stream'   => false,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user',   'content' => json_encode(['entities' => $entityBatch], JSON_UNESCAPED_UNICODE)],
            ],
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $responseRaw = @file_get_contents($this->ollamaHost . '/api/chat', false, $context);
        if ($responseRaw === false) {
            fwrite(STDERR, "Warning: Could not connect to Ollama at {$this->ollamaHost}\n");

            return [];
        }

        $response = json_decode($responseRaw, true);
        if (!is_array($response) || !isset($response['message']['content'])) {
            fwrite(STDERR, "Warning: Unexpected Ollama response format\n");

            return [];
        }

        return $this->parseDescriptions($response['message']['content']);
    }

    private function parseDescriptions(string $raw): array
    {
        // Strip markdown code fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned ?? $raw);
        $cleaned = trim($cleaned ?? $raw);

        // Extract JSON object from response
        if (!preg_match('/\{.*\}/s', $cleaned, $m)) {
            fwrite(STDERR, "Warning: Failed to parse JSON from Ollama response\n");

            return [];
        }

        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "Warning: Failed to parse JSON from Ollama response\n");

            return [];
        }

        // Primary format: {"entity_id": "description string", ...}
        $flat = array_filter($decoded, 'is_string');
        if (!empty($flat)) {
            return $flat;
        }

        // Fallback format: {"entity_ids": [...], "descriptions": [...]}
        // Some smaller models return parallel arrays instead of a flat map.
        if (isset($decoded['entity_ids'], $decoded['descriptions']) &&
            is_array($decoded['entity_ids']) && is_array($decoded['descriptions'])
        ) {
            $result = [];
            foreach ($decoded['entity_ids'] as $i => $id) {
                if (isset($decoded['descriptions'][$i]) && is_string($id) && is_string($decoded['descriptions'][$i])) {
                    $result[$id] = $decoded['descriptions'][$i];
                }
            }

            return $result;
        }

        fwrite(STDERR, "Warning: Failed to parse JSON from Ollama response\n");

        return [];
    }
}
