<?php

declare(strict_types=1);

namespace PluginProfiler\LLM;

interface LLMClientInterface
{
    /**
     * Generate descriptions for a batch of entities.
     *
     * @param array<array<string, mixed>> $entityBatch Array of entity metadata maps
     * @return array<string, string>  entity_id => description
     */
    public function generateDescriptions(array $entityBatch): array;

    /**
     * Generate a free-form text response for a single prompt.
     * Used for tasks like plugin summaries that don't fit the entity-batch pattern.
     *
     * Returns null on failure.
     */
    public function generateText(string $prompt): ?string;
}
