<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class Node
{
    public ?string $description = null;
    public ?string $sourcePreview = null;

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $type,
        public readonly ?string $subtype,
        public readonly string $file,
        public readonly int $line,
        public readonly array $metadata,
        public readonly ?string $docblock = null,
    ) {}

    public static function make(
        string $id,
        string $label,
        string $type,
        string $file,
        int $line = 0,
        ?string $subtype = null,
        array $metadata = [],
        ?string $docblock = null,
    ): self {
        $defaults = [
            'namespace'        => null,
            'extends'          => null,
            'implements'       => [],
            'visibility'       => null,
            'params'           => [],
            'return_type'      => null,
            'priority'         => null,
            'hook_name'        => null,
            'http_method'      => null,
            'route'            => null,
            'operation'        => null,
            'key'              => null,
            // JS / Block extensions
            'block_name'       => null,
            'block_category'   => null,
            'block_attributes' => null,
            'render_template'  => null,
            'js_assets'        => [],
        ];

        return new self(
            id: self::sanitizeId($id),
            label: $label,
            type: $type,
            subtype: $subtype,
            file: $file,
            line: $line,
            metadata: array_merge($defaults, $metadata),
            docblock: $docblock,
        );
    }

    public function withDescription(string $description): static
    {
        $clone = clone $this;
        $clone->description = $description;

        return $clone;
    }

    private static function sanitizeId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $id) ?? $id;
    }
}
