<?php

declare(strict_types=1);

namespace PluginProfiler\LLM;

/**
 * Anthropic Claude client using the Messages API.
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
class ClaudeClient implements LLMClientInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
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
        private readonly string $apiKey,
        private readonly string $model = 'claude-haiku-4-5-20251001',
        private readonly int $timeout = 60,
    ) {}

    public function generateDescriptions(array $entityBatch): array
    {
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => 4096,
            'system'     => self::SYSTEM_PROMPT,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => json_encode(['entities' => $entityBatch], JSON_UNESCAPED_UNICODE),
                ],
            ],
        ]);

        $result = $this->postWithRetry($payload);
        if ($result === null) {
            return [];
        }

        $content = $result['content'][0]['text'] ?? null;
        if ($content === null) {
            fwrite(STDERR, "Warning: No content in Claude response\n");

            return [];
        }

        return $this->parseDescriptions($content);
    }

    /**
     * POST with one retry on failure.
     *
     * @return array<string, mixed>|null
     */
    private function postWithRetry(string $payload, int $attempt = 0): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                ]) . "\r\n",
                'content'       => $payload,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $responseRaw = @file_get_contents(self::API_URL, false, $context);
        if ($responseRaw === false) {
            if ($attempt === 0) {
                fwrite(STDERR, "Warning: Claude API request failed, retrying...\n");

                return $this->postWithRetry($payload, 1);
            }
            fwrite(STDERR, "Warning: Claude API request failed after retry\n");

            return null;
        }

        $decoded = json_decode($responseRaw, true);
        if (!is_array($decoded)) {
            if ($attempt === 0) {
                return $this->postWithRetry($payload, 1);
            }
            fwrite(STDERR, "Warning: Invalid JSON from Claude API\n");

            return null;
        }

        if (isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? 'Unknown error';
            fwrite(STDERR, "Warning: Claude API error: $msg\n");
            if ($attempt === 0) {
                return $this->postWithRetry($payload, 1);
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

        fwrite(STDERR, "Warning: Failed to parse JSON from Claude response\n");

        return [];
    }
}
