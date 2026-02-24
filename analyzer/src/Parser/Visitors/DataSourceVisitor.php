<?php

declare(strict_types=1);

namespace PluginProfiler\Parser\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PluginProfiler\Graph\Edge;
use PluginProfiler\Graph\Node as GraphNode;

class DataSourceVisitor extends NamespaceAwareVisitor
{
    // WordPress options API
    private const OPTION_READ    = ['get_option'];
    private const OPTION_WRITE   = ['update_option', 'add_option'];
    private const OPTION_DELETE  = ['delete_option'];
    // Multisite options
    private const SITE_OPTION_READ  = ['get_site_option', 'get_network_option'];
    private const SITE_OPTION_WRITE = ['update_site_option', 'add_site_option', 'update_network_option', 'add_network_option'];
    private const SITE_OPTION_DELETE = ['delete_site_option', 'delete_network_option'];
    // Post meta
    private const POST_META_READ  = ['get_post_meta'];
    private const POST_META_WRITE = ['update_post_meta', 'add_post_meta', 'delete_post_meta'];
    // User meta
    private const USER_META_READ  = ['get_user_meta'];
    private const USER_META_WRITE = ['update_user_meta', 'add_user_meta', 'delete_user_meta'];
    // Term meta
    private const TERM_META_READ  = ['get_term_meta'];
    private const TERM_META_WRITE = ['update_term_meta', 'add_term_meta', 'delete_term_meta'];
    // Comment meta
    private const COMMENT_META_READ  = ['get_comment_meta'];
    private const COMMENT_META_WRITE = ['update_comment_meta', 'add_comment_meta', 'delete_comment_meta'];
    // Transients (site-level transients included)
    private const TRANSIENT_READ  = ['get_transient', 'get_site_transient'];
    private const TRANSIENT_WRITE = ['set_transient', 'set_site_transient'];
    private const TRANSIENT_DELETE = ['delete_transient', 'delete_site_transient'];
    // Object cache
    private const CACHE_READ  = ['wp_cache_get', 'wp_cache_get_multiple'];
    private const CACHE_WRITE = ['wp_cache_set', 'wp_cache_add', 'wp_cache_replace', 'wp_cache_set_multiple'];
    private const CACHE_DELETE = ['wp_cache_delete', 'wp_cache_flush'];
    // $wpdb methods
    private const WPDB_READ   = ['get_results', 'get_row', 'get_var', 'get_col', 'query'];
    private const WPDB_WRITE  = ['insert', 'update', 'replace'];
    private const WPDB_DELETE = ['delete'];

    // Generic PDO — any variable whose name contains "pdo" (e.g. $pdo, $pdoDb).
    // Type inference is not available at this AST level; variable-name heuristics
    // are the standard practice for this class of static analysis tool.
    private const PDO_READ  = ['query', 'prepare'];
    private const PDO_WRITE = ['exec', 'execute'];

    // Generic MySQLi — any variable whose name contains "mysqli".
    private const MYSQLI_READ  = ['query', 'prepare'];
    private const MYSQLI_WRITE = ['execute'];

    /** Current enclosing function/method ID for edge source */
    private string $currentFunctionId = '';

    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->currentFunctionId = '';

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        parent::enterNode($node);

        // Track enclosing function context
        if ($node instanceof Node\Stmt\Function_) {
            $this->currentFunctionId = 'func_' . $node->name->toString();
        }
        if ($node instanceof Node\Stmt\ClassMethod && $this->currentClass !== '') {
            $this->currentFunctionId = 'method_' . $this->currentClass . '_' . $node->name->toString();
        }

        if ($node instanceof Expr\FuncCall) {
            $this->handleFuncCall($node);
        }

        if ($node instanceof Expr\MethodCall) {
            $this->handleWpdbCall($node);
            $this->handlePdoCall($node);
            $this->handleMysqliCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        parent::leaveNode($node);

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $this->currentFunctionId = '';
        }

        return null;
    }

    private function handleFuncCall(Expr\FuncCall $node): void
    {
        $name = $this->getFuncCallName($node);
        if ($name === null) {
            return;
        }

        [$operation, $subtype] = match (true) {
            in_array($name, self::OPTION_READ, true)         => ['read',   'option'],
            in_array($name, self::OPTION_WRITE, true)        => ['write',  'option'],
            in_array($name, self::OPTION_DELETE, true)       => ['delete', 'option'],
            in_array($name, self::SITE_OPTION_READ, true)    => ['read',   'site_option'],
            in_array($name, self::SITE_OPTION_WRITE, true)   => ['write',  'site_option'],
            in_array($name, self::SITE_OPTION_DELETE, true)  => ['delete', 'site_option'],
            in_array($name, self::POST_META_READ, true)      => ['read',   'post_meta'],
            in_array($name, self::POST_META_WRITE, true)     => ['write',  'post_meta'],
            in_array($name, self::USER_META_READ, true)      => ['read',   'user_meta'],
            in_array($name, self::USER_META_WRITE, true)     => ['write',  'user_meta'],
            in_array($name, self::TERM_META_READ, true)      => ['read',   'term_meta'],
            in_array($name, self::TERM_META_WRITE, true)     => ['write',  'term_meta'],
            in_array($name, self::COMMENT_META_READ, true)   => ['read',   'comment_meta'],
            in_array($name, self::COMMENT_META_WRITE, true)  => ['write',  'comment_meta'],
            in_array($name, self::TRANSIENT_READ, true)      => ['read',   'transient'],
            in_array($name, self::TRANSIENT_WRITE, true)     => ['write',  'transient'],
            in_array($name, self::TRANSIENT_DELETE, true)    => ['delete', 'transient'],
            in_array($name, self::CACHE_READ, true)          => ['read',   'cache'],
            in_array($name, self::CACHE_WRITE, true)         => ['write',  'cache'],
            in_array($name, self::CACHE_DELETE, true)        => ['delete', 'cache'],
            default                                          => [null,     null],
        };

        if ($operation === null) {
            return;
        }

        $key = $this->resolveKeyArg($node, $subtype);
        $this->createDataNode($operation, $subtype, $key, $node->getStartLine(), $name);
    }

    private function handleWpdbCall(Expr\MethodCall $node): void
    {
        // Only match $wpdb->method()
        if (!$node->var instanceof Expr\Variable || $node->var->name !== 'wpdb') {
            return;
        }

        if (!$node->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $node->name->toString();

        $operation = match (true) {
            in_array($methodName, self::WPDB_READ, true)   => 'read',
            in_array($methodName, self::WPDB_WRITE, true)  => 'write',
            in_array($methodName, self::WPDB_DELETE, true) => 'delete',
            default                                         => null,
        };

        if ($operation === null) {
            return;
        }

        // For $wpdb, key is the SQL or table name (first arg if string)
        $key = null;
        if (isset($node->args[0]) && $node->args[0]->value instanceof Scalar\String_) {
            // Truncate long SQL to a safe label
            $key = substr($node->args[0]->value->value, 0, 80);
        }

        $this->createDataNode($operation, 'database', $key, $node->getStartLine(), '$wpdb->' . $methodName);
    }

    /**
     * Detect PDO database calls on variables whose name contains "pdo"
     * (e.g. $pdo, $pdoDb, $myPdoConnection).
     *
     * Matched methods: query / prepare (read), exec / execute (write).
     */
    private function handlePdoCall(Expr\MethodCall $node): void
    {
        if (!$node->var instanceof Expr\Variable) {
            return;
        }

        $varName = is_string($node->var->name) ? strtolower($node->var->name) : '';
        if (!str_contains($varName, 'pdo')) {
            return;
        }

        if (!$node->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $node->name->toString();

        $operation = match (true) {
            in_array($methodName, self::PDO_READ, true)  => 'read',
            in_array($methodName, self::PDO_WRITE, true) => 'write',
            default                                       => null,
        };

        if ($operation === null) {
            return;
        }

        $this->createDataNode($operation, 'database', null, $node->getStartLine(), '$pdo->' . $methodName);
    }

    /**
     * Detect MySQLi database calls on variables whose name contains "mysqli"
     * (e.g. $mysqli, $db_mysqli, $mysqliConn).
     *
     * Matched methods: query / prepare (read), execute (write).
     */
    private function handleMysqliCall(Expr\MethodCall $node): void
    {
        if (!$node->var instanceof Expr\Variable) {
            return;
        }

        $varName = is_string($node->var->name) ? strtolower($node->var->name) : '';
        if (!str_contains($varName, 'mysqli')) {
            return;
        }

        if (!$node->name instanceof Node\Identifier) {
            return;
        }

        $methodName = $node->name->toString();

        $operation = match (true) {
            in_array($methodName, self::MYSQLI_READ, true)  => 'read',
            in_array($methodName, self::MYSQLI_WRITE, true) => 'write',
            default                                          => null,
        };

        if ($operation === null) {
            return;
        }

        $this->createDataNode($operation, 'database', null, $node->getStartLine(), '$mysqli->' . $methodName);
    }

    private function resolveKeyArg(Expr\FuncCall $node, ?string $subtype): ?string
    {
        // The key argument position varies by function type
        $keyArgIndex = match ($subtype) {
            'option', 'site_option' => 0,
            'post_meta'    => 1, // get_post_meta($post_id, $meta_key, ...)
            'user_meta'    => 1, // get_user_meta($user_id, $meta_key, ...)
            'term_meta'    => 1, // get_term_meta($term_id, $meta_key, ...)
            'comment_meta' => 1, // get_comment_meta($comment_id, $meta_key, ...)
            'transient'    => 0,
            'cache'        => 0, // wp_cache_get($key, $group, ...)
            default        => 0,
        };

        if (!isset($node->args[$keyArgIndex])) {
            return null;
        }

        $argValue = $node->args[$keyArgIndex]->value;
        if ($argValue instanceof Scalar\String_) {
            return $argValue->value;
        }

        return null;
    }

    private function createDataNode(string $operation, ?string $subtype, ?string $key, int $line, ?string $apiFunction = null): void
    {
        // For database operations (wpdb, PDO, MySQLi) the key is often null
        // because it's raw SQL, not a named option/meta key. Use a fallback.
        // For keyed APIs (options, meta, transients, cache), skip unresolvable
        // dynamic keys — each creates a separate noise node.
        if ($key === null) {
            if ($subtype === 'database') {
                $key = $subtype;
            } else {
                return;
            }
        }

        $nodeId = 'data_' . $operation . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
        $file   = $this->collection->getCurrentFile();

        $dataNode = GraphNode::make(
            id: $nodeId,
            label: $key,
            type: 'data_source',
            file: $file,
            line: $line,
            subtype: $subtype,
            metadata: [
                'operation' => $operation,
                'key'       => $key,
            ],
        );
        $this->collection->addNode($dataNode);

        if ($this->currentFunctionId !== '') {
            $edgeType  = $operation === 'read' ? 'reads_data' : 'writes_data';
            $edgeLabel = $operation === 'read' ? 'reads' : 'writes';
            $edgeMeta  = $apiFunction !== null ? ['api_function' => $apiFunction] : [];
            $this->collection->addEdge(
                Edge::make($this->currentFunctionId, $nodeId, $edgeType, $edgeLabel, $edgeMeta)
            );
        }
    }
}
