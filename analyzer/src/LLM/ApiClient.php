<?php

declare(strict_types=1);

namespace PluginProfiler\LLM;

class ApiClient implements LLMClientInterface
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

    private const PROVIDER_BASE_URLS = [
        'gemini'   => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'openai'   => 'https://api.openai.com/v1/',
        'deepseek' => 'https://api.deepseek.com/v1/',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 30,
    ) {}

    public static function forProvider(string $provider, string $apiKey, string $model, int $timeout = 30): self
    {
        $baseUrl = self::PROVIDER_BASE_URLS[$provider] ?? self::PROVIDER_BASE_URLS['openai'];

        return new self($apiKey, $baseUrl, $model, $timeout);
    }

    public function generateDescriptions(array $entityBatch): array
    {
        $payload = json_encode([
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user',   'content' => json_encode(['entities' => $entityBatch], JSON_UNESCAPED_UNICODE)],
            ],
        ]);

        $result = $this->postWithRetry($this->baseUrl . 'chat/completions', $payload);
        if ($result === null) {
            return [];
        }

        $content = $result['choices'][0]['message']['content'] ?? null;
        if ($content === null) {
            fwrite(STDERR, "Warning: No content in API response\n");

            return [];
        }

        return $this->parseDescriptions($content);
    }

    /**
     * POST with one retry on failure.
     *
     * @return array<string, mixed>|null
     */
    private function postWithRetry(string $url, string $payload, int $attempt = 0): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$this->apiKey}\r\n",
                'content'       => $payload,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $responseRaw = @file_get_contents($url, false, $context);
        if ($responseRaw === false) {
            if ($attempt === 0) {
                fwrite(STDERR, "Warning: API request failed, retrying...\n");

                return $this->postWithRetry($url, $payload, 1);
            }
            fwrite(STDERR, "Warning: API request failed after retry\n");

            return null;
        }

        $decoded = json_decode($responseRaw, true);
        if (!is_array($decoded)) {
            if ($attempt === 0) {
                return $this->postWithRetry($url, $payload, 1);
            }
            fwrite(STDERR, "Warning: Invalid JSON from API\n");

            return null;
        }

        // Check for API-level errors
        if (isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? 'Unknown API error';
            fwrite(STDERR, "Warning: API error: $msg\n");
            if ($attempt === 0) {
                return $this->postWithRetry($url, $payload, 1);
            }

            return null;
        }

        return $decoded;
    }

    private function parseDescriptions(string $raw): array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned ?? $raw);
        $cleaned = trim($cleaned ?? $raw);

        if (preg_match('/\{.*\}/s', $cleaned, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return array_filter($decoded, 'is_string');
            }
        }

        fwrite(STDERR, "Warning: Failed to parse JSON from API response\n");

        return [];
    }
}
