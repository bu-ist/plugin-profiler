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
}
