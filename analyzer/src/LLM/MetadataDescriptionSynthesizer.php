<?php

declare(strict_types=1);

namespace PluginProfiler\LLM;

use PluginProfiler\Graph\Graph;
use PluginProfiler\Graph\Node;

/**
 * Generates human-readable descriptions from node metadata alone,
 * without requiring an LLM.
 *
 * This synthesizer builds a sentence for each node type using the
 * structured metadata already collected during parsing (namespace,
 * params, return_type, operation, key, http_method, route, etc.).
 *
 * It runs after DocblockDescriptionExtractor so that docblock-derived
 * descriptions take precedence. Only nodes with description === null
 * receive a synthesized description.
 */
class MetadataDescriptionSynthesizer
{
    /**
     * Build an edge-count index for the graph so we can mention
     * connection counts in descriptions (e.g. "Registered with 3 callbacks").
     *
     * @return array<string, array{in: int, out: int, types: array<string, int>}>
     */
    private function buildEdgeIndex(Graph $graph): array
    {
        $index = [];
        foreach ($graph->edges as $edge) {
            if (!isset($index[$edge->source])) {
                $index[$edge->source] = ['in' => 0, 'out' => 0, 'types' => []];
            }
            if (!isset($index[$edge->target])) {
                $index[$edge->target] = ['in' => 0, 'out' => 0, 'types' => []];
            }
            $index[$edge->source]['out']++;
            $index[$edge->target]['in']++;

            $targetTypes = &$index[$edge->target]['types'];
            $targetTypes[$edge->type] = ($targetTypes[$edge->type] ?? 0) + 1;

            $sourceTypes = &$index[$edge->source]['types'];
            $sourceTypes[$edge->type] = ($sourceTypes[$edge->type] ?? 0) + 1;
        }

        return $index;
    }

    public function synthesize(Graph $graph): void
    {
        $edgeIndex = $this->buildEdgeIndex($graph);

        foreach ($graph->nodes as $node) {
            if ($node->description !== null) {
                continue;
            }

            $desc = $this->synthesizeForNode($node, $edgeIndex[$node->id] ?? null);
            if ($desc !== null) {
                $node->description = $desc;
            }
        }
    }

    /**
     * @param array{in: int, out: int, types: array<string, int>}|null $edges
     */
    private function synthesizeForNode(Node $node, ?array $edges): ?string
    {
        $desc = match ($node->type) {
            'class'            => $this->synthesizeClass($node),
            'interface'        => $this->synthesizeInterface($node, $edges),
            'trait'            => $this->synthesizeTrait($node),
            'function'         => $this->synthesizeFunction($node),
            'method'           => $this->synthesizeMethod($node),
            'hook'             => $this->synthesizeHook($node, $edges),
            'rest_endpoint'    => $this->synthesizeRestEndpoint($node),
            'ajax_handler'     => $this->synthesizeAjaxHandler($node),
            'shortcode'        => $this->synthesizeShortcode($node),
            'admin_page'       => $this->synthesizeAdminPage($node),
            'cron_job'         => $this->synthesizeCronJob($node),
            'post_type'        => $this->synthesizePostType($node),
            'taxonomy'         => $this->synthesizeTaxonomy($node),
            'data_source'      => $this->synthesizeDataSource($node),
            'http_call'        => $this->synthesizeHttpCall($node),
            'gutenberg_block'  => $this->synthesizeBlock($node),
            'react_component'  => $this->synthesizeReactComponent($node),
            'react_hook'       => $this->synthesizeReactHook($node),
            'js_function'      => $this->synthesizeJsFunction($node),
            'js_class'         => $this->synthesizeJsClass($node),
            'js_hook'          => $this->synthesizeJsHook($node),
            'js_api_call'      => $this->synthesizeJsApiCall($node),
            'fetch_call',
            'axios_call'       => $this->synthesizeFetchCall($node),
            'wp_store'         => $this->synthesizeWpStore($node),
            'file'             => $this->synthesizeFile($node, $edges),
            'namespace'        => "PHP namespace group.",
            'dir'              => "JavaScript directory group.",
            default            => null,
        };

        // Ensure the description always starts with an uppercase letter
        if ($desc !== null) {
            $desc = ucfirst($desc);
        }

        return $desc;
    }

    private function synthesizeClass(Node $node): string
    {
        $parts = ['PHP class'];
        $ns = $node->metadata['namespace'] ?? null;
        if ($ns !== null && $ns !== '') {
            $parts[] = "in namespace {$ns}";
        }

        $detail = [];
        $extends = $node->metadata['extends'] ?? null;
        if ($extends !== null && $extends !== '') {
            $detail[] = "extends {$extends}";
        }
        $implements = $node->metadata['implements'] ?? [];
        if (!empty($implements)) {
            $detail[] = 'implements ' . implode(', ', $implements);
        }

        $result = implode(' ', $parts);
        if (!empty($detail)) {
            $result .= '. ' . ucfirst(implode(', ', $detail)) . '.';
        } else {
            $result .= '.';
        }

        return $result;
    }

    private function synthesizeInterface(Node $node, ?array $edges): string
    {
        $parts = ['PHP interface'];
        $ns = $node->metadata['namespace'] ?? null;
        if ($ns !== null && $ns !== '') {
            $parts[] = "in namespace {$ns}";
        }

        $methodCount = $edges['types']['has_method'] ?? 0;
        if ($methodCount > 0) {
            $parts[] = "with {$methodCount} method" . ($methodCount !== 1 ? 's' : '');
        }

        return implode(' ', $parts) . '.';
    }

    private function synthesizeTrait(Node $node): string
    {
        $parts = ['PHP trait'];
        $ns = $node->metadata['namespace'] ?? null;
        if ($ns !== null && $ns !== '') {
            $parts[] = "in namespace {$ns}";
        }

        return implode(' ', $parts) . '.';
    }

    private function synthesizeFunction(Node $node): string
    {
        $parts = [];
        $visibility = $node->metadata['visibility'] ?? null;
        if ($visibility !== null && $visibility !== '') {
            $parts[] = ucfirst($visibility);
        }

        $parts[] = 'standalone PHP function';

        $params = $node->metadata['params'] ?? [];
        if (!empty($params)) {
            $paramStr = $this->formatParams($params);
            $parts[] = "accepting {$paramStr}";
        }

        $returnType = $node->metadata['return_type'] ?? null;
        if ($returnType !== null && $returnType !== '') {
            $parts[] = "returns {$returnType}";
            $result = implode(', ', $this->splitAtLastComma($parts)) . '.';
        } else {
            $result = implode(' ', $parts) . '.';
        }

        return $result . $this->securitySuffix($node);
    }

    private function synthesizeMethod(Node $node): string
    {
        $parts = [];
        $visibility = $node->metadata['visibility'] ?? null;
        if ($visibility !== null && $visibility !== '') {
            $parts[] = ucfirst($visibility) . ' method';
        } else {
            $parts[] = 'Method';
        }

        // Extract class name from the node ID: method_{Class}_{name}
        $className = $this->extractClassFromMethodId($node->id);
        if ($className !== null) {
            $parts[] = "of {$className}";
        }

        $result = implode(' ', $parts);

        $detail = [];
        $params = $node->metadata['params'] ?? [];
        if (!empty($params)) {
            $detail[] = 'Accepts ' . $this->formatParams($params);
        }

        $returnType = $node->metadata['return_type'] ?? null;
        if ($returnType !== null && $returnType !== '') {
            $detail[] = "returns {$returnType}";
        }

        if (!empty($detail)) {
            $result .= '. ' . implode(', ', $detail) . '.';
        } else {
            $result .= '.';
        }

        return $result . $this->securitySuffix($node);
    }

    private function synthesizeHook(Node $node, ?array $edges): string
    {
        $subtype = $node->subtype ?? 'action';
        $hookName = $node->metadata['hook_name'] ?? $node->label;

        $parts = ["WordPress {$subtype} hook"];
        if ($hookName !== 'dynamic' && $hookName !== $node->label) {
            $parts[] = "'{$hookName}'";
        }

        $priority = $node->metadata['priority'] ?? 10;
        if ($priority !== 10) {
            $parts[] = "registered with priority {$priority}";
        }

        $callbackCount = $edges['types']['registers_hook'] ?? 0;
        if ($callbackCount > 1) {
            $parts[] = "({$callbackCount} callbacks)";
        }

        return implode(' ', $parts) . '.';
    }

    private function synthesizeRestEndpoint(Node $node): string
    {
        $method = $node->metadata['http_method'] ?? 'GET';
        $route = $node->metadata['route'] ?? $node->label;

        $parts = ["REST API endpoint: {$method} {$route}"];

        $capability = $node->metadata['capability'] ?? null;
        if ($capability !== null) {
            if ($capability === '__return_true') {
                $parts[] = 'Public (no auth required)';
            } else {
                $parts[] = "Requires '{$capability}' capability";
            }
        }

        $nonceVerified = $node->metadata['nonce_verified'] ?? false;
        if ($nonceVerified) {
            $parts[] = 'Nonce-verified';
        }

        $sanitization = $node->metadata['sanitization_count'] ?? 0;
        if ($sanitization > 0) {
            $parts[] = "{$sanitization} sanitization call" . ($sanitization !== 1 ? 's' : '');
        }

        return implode('. ', $parts) . '.';
    }

    private function synthesizeAjaxHandler(Node $node): string
    {
        $hookName = $node->metadata['hook_name'] ?? '';
        $isNoPriv = str_starts_with($hookName, 'wp_ajax_nopriv_');

        $parts = ["AJAX handler for action '{$node->label}'"];
        if ($isNoPriv) {
            $parts[] = 'Accessible to unauthenticated users (nopriv)';
        } else {
            $parts[] = 'Authenticated users only (wp_ajax_*)';
        }

        $nonceVerified = $node->metadata['nonce_verified'] ?? false;
        if ($nonceVerified) {
            $parts[] = 'Nonce-verified';
        }

        $sanitization = $node->metadata['sanitization_count'] ?? 0;
        if ($sanitization > 0) {
            $parts[] = "{$sanitization} sanitization call" . ($sanitization !== 1 ? 's' : '');
        }

        return implode('. ', $parts) . '.';
    }

    private function synthesizeShortcode(Node $node): string
    {
        return "WordPress shortcode [{$node->label}].";
    }

    private function synthesizeAdminPage(Node $node): string
    {
        return "WordPress admin page '{$node->label}'.";
    }

    private function synthesizeCronJob(Node $node): string
    {
        return "Scheduled cron event '{$node->label}'.";
    }

    private function synthesizePostType(Node $node): string
    {
        return "Custom post type '{$node->label}'.";
    }

    private function synthesizeTaxonomy(Node $node): string
    {
        return "Custom taxonomy '{$node->label}'.";
    }

    private function synthesizeDataSource(Node $node): string
    {
        $operation = $node->metadata['operation'] ?? 'read';
        $key = $node->metadata['key'] ?? null;
        $subtype = $node->subtype;

        $verb = match ($operation) {
            'write'  => 'Writes to',
            'delete' => 'Deletes',
            default  => 'Reads',
        };

        $target = match ($subtype) {
            'option'       => 'WordPress option',
            'site_option'  => 'WordPress site option',
            'post_meta'    => 'post meta',
            'user_meta'    => 'user meta',
            'term_meta'    => 'term meta',
            'comment_meta' => 'comment meta',
            'transient'    => 'WordPress transient',
            'cache'        => 'WordPress object cache',
            'database'     => 'database',
            default        => 'data store',
        };

        $result = "{$verb} {$target}";
        if ($key !== null) {
            $result .= " '{$key}'";
        }

        return $result . '.';
    }

    private function synthesizeHttpCall(Node $node): string
    {
        $method = $node->metadata['http_method'] ?? 'GET';
        $route = $node->metadata['route'] ?? null;

        $result = "Outbound HTTP {$method} request";
        if ($route !== null) {
            $result .= " to {$route}";
        }

        return $result . '.';
    }

    private function synthesizeBlock(Node $node): string
    {
        $blockName = $node->metadata['block_name'] ?? $node->label;
        $parts = ["Gutenberg block '{$blockName}'"];

        $category = $node->metadata['block_category'] ?? null;
        if ($category !== null) {
            $parts[] = "Category: {$category}";
        }

        return implode('. ', $parts) . '.';
    }

    private function synthesizeReactComponent(Node $node): string
    {
        return "React component '{$node->label}'.";
    }

    private function synthesizeReactHook(Node $node): string
    {
        return "React hook '{$node->label}'.";
    }

    private function synthesizeJsFunction(Node $node): string
    {
        return "JavaScript function.";
    }

    private function synthesizeJsClass(Node $node): string
    {
        return "JavaScript class.";
    }

    private function synthesizeJsHook(Node $node): string
    {
        return "WordPress JS hook '{$node->label}'.";
    }

    private function synthesizeJsApiCall(Node $node): string
    {
        $route = $node->metadata['route'] ?? null;
        if ($route !== null) {
            return "JavaScript API call to {$route}.";
        }

        return "JavaScript API call.";
    }

    private function synthesizeFetchCall(Node $node): string
    {
        $route = $node->metadata['route'] ?? null;
        $method = $node->metadata['http_method'] ?? null;

        $parts = [$node->type === 'axios_call' ? 'Axios' : 'Fetch'];
        if ($method !== null) {
            $parts[] = $method;
        }
        $parts[] = 'request';
        if ($route !== null) {
            $parts[] = "to {$route}";
        }

        return implode(' ', $parts) . '.';
    }

    private function synthesizeWpStore(Node $node): string
    {
        return "WordPress data store '{$node->label}'.";
    }

    private function synthesizeFile(Node $node, ?array $edges): string
    {
        $subtype = $node->subtype;
        if ($subtype === 'script') {
            return 'Enqueued JavaScript asset.';
        }
        if ($subtype === 'style') {
            return 'Enqueued CSS stylesheet.';
        }

        // Determine language from the file extension
        $ext = strtolower(pathinfo($node->file ?? $node->label, PATHINFO_EXTENSION));
        $isJs = in_array($ext, ['js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs'], true);
        $lang = $isJs ? 'JavaScript' : 'PHP';

        $includeCount = $edges['types']['includes'] ?? 0;
        if ($includeCount > 0) {
            return "{$lang} file. Includes {$includeCount} other file" . ($includeCount !== 1 ? 's' : '') . '.';
        }

        return "{$lang} file.";
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Format a params array as "N parameter(s) (type $name, type $name)".
     *
     * @param array<string|array<string, string>> $params
     */
    private function formatParams(array $params): string
    {
        $count = count($params);
        $label = "{$count} parameter" . ($count !== 1 ? 's' : '');

        // If params are detailed (arrays with type/name), show them
        $details = [];
        foreach ($params as $param) {
            if (is_array($param)) {
                $type = $param['type'] ?? '';
                $name = $param['name'] ?? '';
                $details[] = trim("{$type} \${$name}");
            } elseif (is_string($param)) {
                $details[] = $param;
            }
        }

        if (!empty($details)) {
            return "{$label} (" . implode(', ', $details) . ')';
        }

        return $label;
    }

    /**
     * Extract the class name from a method node ID like "method_ClassName_methodName".
     */
    private function extractClassFromMethodId(string $id): ?string
    {
        if (!str_starts_with($id, 'method_')) {
            return null;
        }

        $rest = substr($id, strlen('method_'));
        $lastUnderscore = strrpos($rest, '_');
        if ($lastUnderscore === false) {
            return null;
        }

        return substr($rest, 0, $lastUnderscore);
    }

    /**
     * Split parts so that the last element gets a comma-separated join
     * while earlier elements use spaces. Used for "accepts X, returns Y" phrasing.
     *
     * @param array<string> $parts
     * @return array<string>
     */
    private function splitAtLastComma(array $parts): array
    {
        if (count($parts) <= 1) {
            return $parts;
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /**
     * Build a security-context suffix for function/method nodes that have
     * been annotated by SecurityAnnotator (capability, nonce, sanitization).
     */
    private function securitySuffix(Node $node): string
    {
        $parts = [];

        $capability = $node->metadata['capability'] ?? null;
        if ($capability !== null) {
            $parts[] = "Requires '{$capability}' capability";
        }

        $nonceVerified = $node->metadata['nonce_verified'] ?? false;
        if ($nonceVerified) {
            $parts[] = 'nonce-verified';
        }

        $sanitization = $node->metadata['sanitization_count'] ?? 0;
        if ($sanitization > 0) {
            $parts[] = "{$sanitization} sanitization call" . ($sanitization !== 1 ? 's' : '');
        }

        if (empty($parts)) {
            return '';
        }

        return ' ' . ucfirst(implode(', ', $parts)) . '.';
    }
}
