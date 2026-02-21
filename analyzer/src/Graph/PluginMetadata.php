<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

class PluginMetadata
{
    public const ANALYZER_VERSION = '0.1.0';

    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $mainFile,
        public readonly int $totalFiles,
        public readonly int $totalEntities,
        public readonly \DateTimeImmutable $analyzedAt,
        public readonly string $analyzerVersion = self::ANALYZER_VERSION,
        public readonly string $hostPath = '',
        public readonly int $phpFiles = 0,
        public readonly int $jsFiles = 0,
    ) {}
}
