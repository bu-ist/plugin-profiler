# Graph Data Schema (graph-data.json)

The analyzer outputs a single JSON file consumed by the Cytoscape.js frontend. The format follows Cytoscape.js element conventions.

## Top-Level Structure

    {
      "plugin": {
        "name": "Gravity Forms",
        "version": "2.8.1",
        "description": "The best WordPress form plugin.",
        "main_file": "gravityforms.php",
        "total_files": 347,
        "total_entities": 248,
        "analyzed_at": "2026-02-20T14:30:00Z",
        "analyzer_version": "0.1.0"
      },
      "nodes": [ ...node objects... ],
      "edges": [ ...edge objects... ]
    }

## Node Schema

Every node is wrapped in a data object per Cytoscape.js convention:

    {
      "data": {
        "id": "class_GFFormsModel",
        "label": "GFFormsModel",
        "type": "class",
        "subtype": null,
        "file": "includes/class-gf-forms-model.php",
        "line": 12,
        "metadata": {
          "namespace": "GravityForms",
          "extends": "GFBaseModel",
          "implements": [],
          "visibility": "public",
          "params": [],
          "return_type": null,
          "priority": null,
          "hook_name": null,
          "http_method": null,
          "route": null,
          "operation": null,
          "key": null
        },
        "docblock": "Handles all form data operations...",
        "description": "AI-generated description here.",
        "source_preview": "class GFFormsModel extends GFBaseModel {\n  ..."
      }
    }

### Node Types

- class (null) — PHP class
- interface (null) — PHP interface
- trait (null) — PHP trait
- function (null) — Standalone PHP function
- method (null) — Class method
- hook (action) — WordPress action hook
- hook (filter) — WordPress filter hook
- rest_endpoint (null) — REST API route
- ajax_handler (null) — AJAX handler
- shortcode (null) — Shortcode registration
- admin_page (null) — Admin menu/submenu page
- cron_job (null) — Scheduled event
- post_type (null) — Custom post type
- taxonomy (null) — Custom taxonomy
- data_source (option) — WP Options API
- data_source (post_meta) — Post meta
- data_source (user_meta) — User meta
- data_source (transient) — Transient
- data_source (database) — Direct $wpdb query
- http_call (null) — Outbound HTTP request
- file (null) — PHP file

## Edge Schema

    {
      "data": {
        "id": "e_1",
        "source": "method_GFFormsModel_get_form_meta",
        "target": "data_read_rg_form_meta",
        "type": "reads_data",
        "label": "reads"
      }
    }

### Edge Types

- defines: file -> class/function, label "defines"
- extends: class -> class, label "extends"
- implements: class -> interface, label "implements"
- has_method: class -> method, label "has"
- registers_hook: function/method -> hook, label "registers"
- triggers_hook: function/method -> hook, label "triggers"
- calls: function/method -> function/method, label "calls"
- includes: file -> file, label "includes"
- reads_ function/method -> data_source, label "reads"
- writes_ function/method -> data_source, label "writes"
- http_request: function/method -> http_call, label "requests"
- handles: function/method -> rest_endpoint/ajax/shortcode/admin_page, label "handles"

## Validation

The graph JSON must satisfy:
- Every edge source and target must reference an existing node id
- No duplicate node IDs
- Every node has at minimum: id, label, type, file
- source_preview is max 30 lines of code, UTF-8 safe
