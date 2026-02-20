<?php

declare(strict_types=1);

namespace PluginProfiler\LLM;

class OllamaClient implements LLMClientInterface
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a WordPress plugin architecture expert. You will receive metadata
about PHP and JavaScript entities extracted from a WordPress plugin via static analysis.

For each entity, write a clear 2-3 sentence description explaining:
1. What this entity does
2. How it fits into the plugin's architecture
3. Any important side effects, dependencies, or external interactions

Use precise technical language. Reference specific hook names, class
relationships, and data operations mentioned in the metadata. Do not
speculate about behavior not evident from the metadata.

Respond with a JSON object mapping entity IDs to descriptions.
PROMPT;

    public function __construct(
        private readonly string $ollamaHost,
        private readonly string $model,
        private readonly int $timeout = 120,
    ) {}

    public function generateDescriptions(array $entityBatch): array
    {
        $prompt  = self::SYSTEM_PROMPT . "\n\n" . json_encode(['entities' => $entityBatch], JSON_UNESCAPED_UNICODE);
        $payload = json_encode([
            'model'  => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ]);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => $this->timeout,
            ],
        ]);

        $responseRaw = @file_get_contents($this->ollamaHost . '/api/generate', false, $context);
        if ($responseRaw === false) {
            fwrite(STDERR, "Warning: Could not connect to Ollama at {$this->ollamaHost}\n");

            return [];
        }

        $response = json_decode($responseRaw, true);
        if (!is_array($response) || !isset($response['response'])) {
            fwrite(STDERR, "Warning: Unexpected Ollama response format\n");

            return [];
        }

        return $this->parseDescriptions($response['response']);
    }

    private function parseDescriptions(string $raw): array
    {
        // Strip markdown code fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned ?? $raw);
        $cleaned = trim($cleaned ?? $raw);

        // Extract JSON object from response
        if (preg_match('/\{.*\}/s', $cleaned, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return array_filter($decoded, 'is_string');
            }
        }

        fwrite(STDERR, "Warning: Failed to parse JSON from Ollama response\n");

        return [];
    }
}
