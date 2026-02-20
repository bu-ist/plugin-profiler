# Visualization Layer

## Technology: Cytoscape.js 3.x

Interactive graph visualization library loaded via CDN. No build step required.

Reference: https://js.cytoscape.org

## Graph Configuration

### Layout Options (user-switchable)

- dagre (cytoscape-dagre): Hierarchical top-down, best for class hierarchies. DEFAULT.
- cose (built-in): Force-directed, good for exploring clusters.
- breadthfirst (built-in): Tree-like from root nodes.
- grid (built-in): Uniform grid, useful for large graphs.

### Node Styling

    const NODE_STYLES = {
      class:         { shape: 'round-rectangle', color: '#3B82F6', borderWidth: 2 },
      interface:     { shape: 'round-rectangle', color: '#3B82F6', borderStyle: 'dashed' },
      trait:         { shape: 'round-rectangle', color: '#3B82F6', borderStyle: 'dotted' },
      function:      { shape: 'roundrectangle', color: '#14B8A6' },
      method:        { shape: 'roundrectangle', color: '#14B8A6' },
      hook_action:   { shape: 'diamond',        color: '#F97316' },
      hook_filter:   { shape: 'diamond',        color: '#EAB308' },
      rest_endpoint: { shape: 'hexagon',        color: '#22C55E' },
      ajax_handler:  { shape: 'hexagon',        color: '#22C55E' },
      shortcode:     { shape: 'tag',            color: '#22C55E' },
      admin_page:    { shape: 'rectangle',      color: '#22C55E' },
      cron_job:      { shape: 'ellipse',        color: '#22C55E' },
      post_type:     { shape: 'barrel',         color: '#22C55E' },
      taxonomy:      { shape: 'barrel',         color: '#22C55E' },
      data_source:   { shape: 'barrel',         color: '#A855F7' },
      http_call:     { shape: 'ellipse',        color: '#EF4444' },
      file:          { shape: 'rectangle',      color: '#6B7280' },
    };

### Edge Styling

- extends / implements: solid, arrow at target
- registers_hook / triggers_hook: dashed, arrow at target
- calls: dotted, arrow at target
- reads_data / writes_ solid, thicker, arrow at target, purple tint
- http_request: wavy, red tint

## Interaction Model

### Click Node -> Sidebar Inspector
Opens right sidebar panel (400px width) with:
1. Header: Entity name + colored type badge
2. AI Description: 2-3 sentences from LLM (or "No description generated" placeholder)
3. File Location: file:line — clickable link using vscode://file/{absolute_path}:{line}
4. Connections: Grouped by relationship type, each link navigates graph to that node
5. Source Preview: First ~30 lines, syntax-highlighted via Prism.js (language: php)
6. PHPDoc: Rendered docblock if present

### Hover Node
- Highlight node + all connected edges
- Dim all unconnected elements (opacity: 0.15)
- Show tooltip with: label, type, file

### Double-Click Node
- Zoom to fit the selected node and all its direct neighbors

### Toolbar (top bar)
- Search: Text input with autocomplete, matches node labels
- Type filters: Toggle buttons for each node type (show/hide)
- Layout switcher: Dropdown to change layout algorithm
- Zoom controls: Zoom in, zoom out, fit-to-screen
- Minimap toggle: Small overview map in corner

### Keyboard Shortcuts
- Escape — Close sidebar, deselect node
- / — Focus search input
- F — Fit graph to screen
- 1-8 — Toggle node types on/off

## Frontend File Responsibilities

- app.js: Main entry: fetch graph-data.json, initialize Cytoscape, bind events
- graph.js: Cytoscape instance config, stylesheet, layout setup
- sidebar.js: Sidebar panel: render entity detail, source preview, connections
- search.js: Search input, autocomplete, type filter toggles
- layouts.js: Layout algorithm configurations, switcher logic
