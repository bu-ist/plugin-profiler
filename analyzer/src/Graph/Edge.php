<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class Edge
{
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $target,
        public readonly string $type,
        public readonly string $label,
    ) {}

    public static function make(string $source, string $target, string $type, string $label): self
    {
        $id = sprintf('e_%s_%s_%s', $source, $type, $target);

        return new self(
            id: $id,
            source: $source,
            target: $target,
            type: $type,
            label: $label,
        );
    }
}
