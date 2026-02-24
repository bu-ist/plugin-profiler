<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class Edge
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $target,
        public readonly string $type,
        public readonly string $label,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function make(string $source, string $target, string $type, string $label, array $metadata = []): self
    {
        $source = Node::sanitizeId($source);
        $target = Node::sanitizeId($target);
        $id     = sprintf('e_%s_%s_%s', $source, $type, $target);

        return new self(
            id: $id,
            source: $source,
            target: $target,
            type: $type,
            label: $label,
            metadata: $metadata,
        );
    }
}
