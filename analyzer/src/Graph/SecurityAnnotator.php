<?php

declare(strict_types=1);

namespace PluginProfiler\Graph;

/**
 * Post-parse security analysis pass.
 *
 * Scans function/method node sourcePreview text for security-relevant patterns:
 * - Capability checks: current_user_can(), user_can()
 * - Nonce verification: wp_verify_nonce(), check_ajax_referer(), wp_check_nonces()
 * - Input sanitization: sanitize_text_field(), esc_html(), absint(), etc.
 *
 * Then propagates findings to connected endpoint nodes (rest_endpoint, ajax_handler)
 * via the edge graph so endpoints show their security posture.
 */
class SecurityAnnotator
{
    /** @var array<string> Patterns that indicate a capability check. */
    private const CAPABILITY_PATTERNS = [
        '/current_user_can\s*\(\s*[\'"]([a-z_]+)[\'"]\s*\)/',
        '/user_can\s*\([^,]+,\s*[\'"]([a-z_]+)[\'"]\s*\)/',
    ];

    /** @var array<string> Functions that indicate nonce verification. */
    private const NONCE_FUNCTIONS = [
        'wp_verify_nonce',
        'check_ajax_referer',
        'check_admin_referer',
        'wp_nonce_field',        // generating nonce is not verifying, but indicates awareness
    ];

    /** @var array<string> Functions that indicate input sanitization. */
    private const SANITIZATION_FUNCTIONS = [
        'sanitize_text_field',
        'sanitize_email',
        'sanitize_file_name',
        'sanitize_html_class',
        'sanitize_key',
        'sanitize_meta',
        'sanitize_mime_type',
        'sanitize_option',
        'sanitize_sql_orderby',
        'sanitize_title',
        'sanitize_url',
        'sanitize_user',
        'absint',
        'intval',
        'wp_kses',
        'wp_kses_post',
        'wp_kses_data',
        'esc_html',
        'esc_attr',
        'esc_url',
        'esc_sql',
        'esc_textarea',
        'wp_unslash',
    ];

    /**
     * Annotate security metadata on function/method nodes by scanning their
     * sourcePreview, then propagate to connected endpoint nodes.
     */
    public function annotate(Graph $graph): void
    {
        // Build index: nodeId → Node for quick lookup
        $nodeIndex = [];
        foreach ($graph->nodes as $node) {
            $nodeIndex[$node->id] = $node;
        }

        // Build reverse edge index: target → [source IDs]
        // And forward index: source → [target IDs]
        $incomingEdges = [];  // target → [(source, type)]
        $outgoingEdges = [];  // source → [(target, type)]
        foreach ($graph->edges as $edge) {
            $incomingEdges[$edge->target][] = ['id' => $edge->source, 'type' => $edge->type];
            $outgoingEdges[$edge->source][] = ['id' => $edge->target, 'type' => $edge->type];
        }

        // Step 1: Scan function/method nodes for security patterns
        foreach ($graph->nodes as $node) {
            if (!in_array($node->type, ['function', 'method'], true)) {
                continue;
            }
            if ($node->sourcePreview === null || $node->sourcePreview === '') {
                continue;
            }

            $this->scanNodeSecurity($node);
        }

        // Step 2: Propagate security metadata to endpoint nodes
        // If an endpoint's registering function has security annotations,
        // copy them to the endpoint node.
        foreach ($graph->nodes as $node) {
            if (!in_array($node->type, ['rest_endpoint', 'ajax_handler'], true)) {
                continue;
            }

            $this->propagateToEndpoint($node, $incomingEdges, $nodeIndex);
        }
    }

    /**
     * Scan a function/method's sourcePreview for security patterns and
     * annotate its metadata accordingly.
     */
    private function scanNodeSecurity(Node $node): void
    {
        $source = $node->sourcePreview;

        // Capability check
        $capability = $this->extractCapability($source);
        if ($capability !== null) {
            $node->metadata = array_merge($node->metadata, ['capability' => $capability]);
        }

        // Nonce verification
        if ($this->hasNonceVerification($source)) {
            $node->metadata = array_merge($node->metadata, ['nonce_verified' => true]);
        }

        // Sanitization count
        $sanitizationCount = $this->countSanitizationCalls($source);
        if ($sanitizationCount > 0) {
            $node->metadata = array_merge($node->metadata, ['sanitization_count' => $sanitizationCount]);
        }
    }

    /**
     * Extract the first capability string from a current_user_can() or user_can() call.
     */
    private function extractCapability(string $source): ?string
    {
        foreach (self::CAPABILITY_PATTERNS as $pattern) {
            if (preg_match($pattern, $source, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Check if the source contains any nonce verification function.
     */
    private function hasNonceVerification(string $source): bool
    {
        foreach (self::NONCE_FUNCTIONS as $func) {
            if (str_contains($source, $func)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count distinct sanitization/escaping function calls in the source.
     */
    private function countSanitizationCalls(string $source): int
    {
        $count = 0;
        foreach (self::SANITIZATION_FUNCTIONS as $func) {
            $count += substr_count($source, $func . '(');
        }

        return $count;
    }

    /**
     * Propagate security metadata from registering functions to an endpoint node.
     *
     * Follows incoming 'registers', 'registers_rest', 'registers_ajax' edges
     * to find the function/method that registered this endpoint, then copies
     * any security metadata from that function to the endpoint.
     *
     * @param array<string, list<array{id: string, type: string}>> $incomingEdges
     * @param array<string, Node> $nodeIndex
     */
    private function propagateToEndpoint(Node $endpoint, array $incomingEdges, array $nodeIndex): void
    {
        $sources = $incomingEdges[$endpoint->id] ?? [];

        foreach ($sources as $source) {
            if (!in_array($source['type'], ['registers', 'registers_rest', 'registers_ajax'], true)) {
                continue;
            }

            $sourceNode = $nodeIndex[$source['id']] ?? null;
            if ($sourceNode === null) {
                continue;
            }

            // Copy security annotations from the registering function
            $capability = $sourceNode->metadata['capability'] ?? null;
            if ($capability !== null && !isset($endpoint->metadata['capability'])) {
                $endpoint->metadata = array_merge($endpoint->metadata, ['capability' => $capability]);
            }

            $nonceVerified = $sourceNode->metadata['nonce_verified'] ?? false;
            if ($nonceVerified && !($endpoint->metadata['nonce_verified'] ?? false)) {
                $endpoint->metadata = array_merge($endpoint->metadata, ['nonce_verified' => true]);
            }

            $sanitizationCount = $sourceNode->metadata['sanitization_count'] ?? 0;
            if ($sanitizationCount > 0) {
                $existing = $endpoint->metadata['sanitization_count'] ?? 0;
                $endpoint->metadata = array_merge($endpoint->metadata, [
                    'sanitization_count' => max($existing, $sanitizationCount),
                ]);
            }
        }
    }
}
