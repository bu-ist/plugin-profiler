<?php

declare(strict_types=1);

namespace PluginProfiler\LLM;

use PluginProfiler\Graph\Graph;

/**
 * Populates node descriptions from their docblock text as a lightweight
 * alternative to LLM-generated descriptions.
 *
 * Applies only to nodes that do not yet have a description set.
 * LLM descriptions always take precedence — this extractor is a no-op for
 * nodes that already have a description.
 *
 * Supported docblock formats:
 *   - PHPDoc blocks (/** ... * /): strips markers and leading " * " decoration,
 *     returns the first non-empty paragraph before any @-tag line.
 *   - Plain text (e.g. block.json "description"): returns the first line,
 *     capped at 200 characters.
 */
class DocblockDescriptionExtractor
{
    /** Maximum character length for an extracted description. */
    private const MAX_LENGTH = 300;

    /**
     * Populate description on nodes that have a docblock but no description yet.
     */
    public function extract(Graph $graph): void
    {
        foreach ($graph->nodes as $node) {
            if ($node->description !== null || $node->docblock === null) {
                continue;
            }

            $summary = $this->extractSummary($node->docblock);
            if ($summary !== null) {
                $node->description = $summary;
            }
        }
    }

    /**
     * Extract a single-line summary from a PHPDoc block or plain-text description.
     */
    private function extractSummary(string $docblock): ?string
    {
        $docblock = trim($docblock);

        if (!str_starts_with($docblock, '/**')) {
            // Plain text (e.g. block.json description field) — return first line
            $first = strtok($docblock, "\n") ?: $docblock;
            $first = trim($first);

            return $first !== '' ? mb_substr($first, 0, self::MAX_LENGTH) : null;
        }

        // PHPDoc block — parse line by line
        $lines        = explode("\n", $docblock);
        $summaryParts = [];

        foreach ($lines as $line) {
            // Strip opening /** and closing */ markers
            $line = (string) preg_replace('/^\s*\/\*+\s?/', '', $line);
            $line = (string) preg_replace('/\s*\*\/\s*$/', '', $line);

            // Strip leading " * " or " *" decoration
            $line = (string) preg_replace('/^\s*\*\s?/', '', $line);
            $line = rtrim($line);

            // Skip bare "/" left after stripping closing */
            if ($line === '/') {
                continue;
            }

            // Stop at @-tags (PHPDoc annotations such as @param, @return, @throws)
            if (str_starts_with($line, '@')) {
                break;
            }

            // A blank line after we have content marks the end of the first paragraph
            if ($line === '' && !empty($summaryParts)) {
                break;
            }

            // Skip leading blank lines before the first content
            if ($line === '' && empty($summaryParts)) {
                continue;
            }

            $summaryParts[] = $line;
        }

        if (empty($summaryParts)) {
            return null;
        }

        // Join multi-line summaries into a single sentence
        $summary = implode(' ', $summaryParts);
        $summary = (string) preg_replace('/\s+/', ' ', $summary);
        $summary = trim($summary);

        return $summary !== '' ? mb_substr($summary, 0, self::MAX_LENGTH) : null;
    }
}
