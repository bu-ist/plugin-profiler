# Analysis Engine

## Core Library: nikic/php-parser v5

The analysis engine uses nikic/php-parser to parse PHP 7/8 source files into an Abstract Syntax Tree (AST). We traverse the AST using the NodeTraverser + NodeVisitor pattern.

Reference: https://github.com/nikic/PHP-Parser

## Pipeline

    FileScanner::scan($pluginDir)
      -> array<SplFileInfo>     (all .php files)
      -> identifies main plugin file (has "Plugin Name:" header)
    
    PluginParser::parse(array<SplFileInfo>)
      -> foreach file:
          $ast = $parser->parse($code);
          $traverser->traverse($ast);
      -> Visitors accumulate entities into shared EntityCollection
    
    GraphBuilder::build(EntityCollection)
      -> resolves cross-references (e.g., callback strings -> function nodes)
      -> outputs Graph { nodes: Node[], edges: Edge[] }
    
    DescriptionGenerator::generate(Graph)
      -> batches entities (25 per request)
      -> sends to Ollama or API
      -> attaches description to each Node
      -> returns enriched Graph
    
    JsonExporter::export(Graph)
      -> writes Cytoscape.js-compatible JSON to /output/graph-data.json

## Visitors (one per concern)

Each Visitor extends PhpParser\NodeVisitorAbstract and implements enterNode() or leaveNode().

### ClassVisitor
- Detects: Stmt\Class_, Stmt\Interface_, Stmt\Trait_
- Extracts: name, namespace, extends, implements, docblock, file, line
- Creates: Node(type=class|interface|trait) + Edge(type=extends|implements)

### FunctionVisitor
- Detects: Stmt\Function_, Stmt\ClassMethod
- Extracts: name, params (name+type), return type, visibility, docblock, file, line
- Creates: Node(type=function|method) + Edge(type=defines) from parent class

### HookVisitor
- Detects: Expr\FuncCall where function name is one of:
  - add_action, add_filter -> creates Edge(type=registers_hook) from caller to hook Node
  - do_action, apply_filters -> creates Edge(type=triggers_hook) from caller to hook Node
  - remove_action, remove_filter -> noted as metadata
- Extracts: hook name (1st arg), callback (2nd arg), priority (3rd arg, default 10)
- Creates: Node(type=hook_action|hook_filter) + relationship edges
- Callback resolution: String -> function name. Array -> [class, method]. Closure -> anonymous (file:line).

### DataSourceVisitor
- Detects: Expr\FuncCall or Expr\MethodCall matching:
  - Options: get_option, update_option, add_option, delete_option
  - Post meta: get_post_meta, update_post_meta, add_post_meta, delete_post_meta
  - User meta: get_user_meta, update_user_meta
  - Transients: get_transient, set_transient, delete_transient
  - Direct DB: $wpdb->query, ->get_results, ->get_row, ->get_var, ->insert, ->update, ->delete, ->prepare
- Extracts: operation (read|write|delete), key/table name (1st arg if string literal), file, line
- Creates: Node(type=data_source) + Edge(type=reads_data|writes_data)

### ExternalInterfaceVisitor
- Detects: Expr\FuncCall matching:
  - REST: register_rest_route -> extracts namespace, route, methods, callback
  - AJAX: add_action('wp_ajax_{name}', ...) -> extracts action name, callback, auth
  - Shortcodes: add_shortcode -> extracts tag, callback
  - Admin pages: add_menu_page, add_submenu_page -> extracts title, slug, callback
  - Cron: wp_schedule_event, wp_schedule_single_event -> extracts hook, recurrence
  - Post types: register_post_type -> extracts slug, args
  - Taxonomies: register_taxonomy -> extracts slug, post types
  - HTTP calls: wp_remote_get, wp_remote_post, wp_remote_request -> extracts URL
- Creates: Appropriate Node + Edge types

### FileVisitor
- Detects: Expr\Include_ (include, include_once, require, require_once)
- Extracts: included file path (resolved relative to current file)
- Creates: Edge(type=includes) from current File node to included File node

## EntityCollection

Shared mutable collection that all Visitors write to during traversal:

    class EntityCollection {
        private array $nodes = [];   // array<string, Node>
        private array $edges = [];   // array<Edge>
    
        public function addNode(Node $node): void;    // deduplicates by ID
        public function addEdge(Edge $edge): void;
        public function getNode(string $id): ?Node;
        public function hasNode(string $id): bool;
        public function toArray(): array;             // { nodes: [], edges: [] }
    }

## Node ID Generation

Deterministic IDs to prevent duplicates:
- Class: class_{namespace}_{name} -> class_GFFormsModel
- Method: method_{class}_{name} -> method_GFFormsModel_get_form_meta
- Function: func_{name} -> func_gravity_forms_setup
- Hook: hook_{type}_{name} -> hook_action_gform_after_submission
- Data source: data_{operation}_{key} -> data_read_gf_settings
- REST endpoint: rest_{method}_{route} -> rest_POST_gf_v2_forms
- File: file_{relative_path} -> file_includes_class-gf-forms-model.php

## Performance Target

For a Gravity Forms-scale plugin (~350 PHP files, ~105K lines):
- File discovery: < 1s
- AST parsing: < 30s
- Entity extraction: < 5s
- Graph building: < 2s
- Total (no LLM): < 40s
