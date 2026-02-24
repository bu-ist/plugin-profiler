/**
 * graph.js — Cytoscape initialisation, node/edge styling, and interaction.
 *
 * Node type metadata (colours, shapes) is imported from constants.js — the
 * single source of truth shared by graph.js, sidebar.js, and search.js.
 * Adding a new node type requires editing only constants.js.
 *
 * EDGE_STYLES remains here because edge rendering logic is Cytoscape-specific
 * and not consumed by other modules.
 */

import { LAYOUTS }    from './layouts.js';
import { NODE_TYPES } from './constants.js';

// ── Edge styles ───────────────────────────────────────────────────────────────
// Each entry maps to a Cytoscape style rule below. Structure:
//   selector string → { partial style overrides }

const EDGE_STYLES = [
  // Inheritance — solid blue, vee arrow
  {
    selector: 'edge[type="extends"], edge[type="implements"], edge[type="uses_trait"]',
    style:    { 'line-style': 'solid', 'width': 2.5, 'line-color': '#60A5FA', 'target-arrow-color': '#60A5FA', 'target-arrow-shape': 'vee' },
  },
  // Instantiation — dotted teal, diamond arrow
  {
    selector: 'edge[type="instantiates"]',
    style:    { 'line-style': 'dotted', 'width': 2, 'line-color': '#2DD4BF', 'target-arrow-color': '#2DD4BF', 'target-arrow-shape': 'diamond' },
  },
  // JS module imports — dashed indigo, chevron arrow
  {
    selector: 'edge[type="imports"]',
    style:    { 'line-style': 'dashed', 'width': 1.5, 'line-color': '#818CF8', 'target-arrow-color': '#818CF8', 'target-arrow-shape': 'chevron' },
  },
  // Function/method calls — solid slate, triangle arrow (default structural colour)
  {
    selector: 'edge[type="calls"]',
    style:    { 'width': 2, 'line-color': '#94A3B8', 'target-arrow-color': '#94A3B8', 'target-arrow-shape': 'triangle' },
  },
  // Structural: has_method, includes, defines — dotted/dashed slate, triangle arrow
  {
    selector: 'edge[type="has_method"], edge[type="defines"]',
    style:    { 'line-style': 'dotted', 'width': 1.5, 'line-color': '#94A3B8', 'target-arrow-color': '#94A3B8', 'target-arrow-shape': 'triangle' },
  },
  {
    selector: 'edge[type="includes"]',
    style:    { 'line-style': 'dashed', 'width': 1.5, 'line-color': '#94A3B8', 'target-arrow-color': '#94A3B8', 'target-arrow-shape': 'triangle' },
  },
  // React component definition — solid cyan, triangle arrow
  {
    selector: 'edge[type="defines_component"]',
    style:    { 'width': 2, 'line-color': '#06B6D4', 'target-arrow-color': '#06B6D4', 'target-arrow-shape': 'triangle' },
  },
  // JS HTTP calls (fetch/axios → http_call node) — solid red, tee arrow
  {
    selector: 'edge[type="http_call"]',
    style:    { 'width': 2.5, 'line-color': '#F87171', 'target-arrow-color': '#F87171', 'target-arrow-shape': 'tee' },
  },
  // JS block registration — dashed pink, circle arrow
  {
    selector: 'edge[type="registers_block"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6', 'target-arrow-shape': 'circle' },
  },
  // WordPress hooks — dashed orange, triangle arrow
  {
    selector: 'edge[type="registers_hook"], edge[type="triggers_hook"], edge[type="js_registers_hook"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#FB923C', 'target-arrow-color': '#FB923C', 'target-arrow-shape': 'triangle' },
  },
  // Hook trigger to handler — solid orange, triangle arrow
  {
    selector: 'edge[type="triggers_handler"]',
    style:    { 'width': 2.5, 'line-style': 'solid', 'line-color': '#FB923C', 'target-arrow-color': '#FB923C', 'target-arrow-shape': 'triangle' },
  },
  // Data reads — solid purple, square arrow
  {
    selector: 'edge[type="reads_data"]',
    style:    { 'width': 2.5, 'line-color': '#C084FC', 'target-arrow-color': '#C084FC', 'target-arrow-shape': 'square' },
  },
  // Data writes — dashed purple, square arrow
  {
    selector: 'edge[type="writes_data"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#C084FC', 'target-arrow-color': '#C084FC', 'target-arrow-shape': 'square' },
  },
  // Outbound HTTP — red, tee arrow
  {
    selector: 'edge[type="http_request"]',
    style:    { 'width': 2.5, 'line-color': '#F87171', 'target-arrow-color': '#F87171', 'target-arrow-shape': 'tee' },
  },
  // Block rendering and asset enqueueing — dotted pink, circle arrow
  {
    selector: 'edge[type="renders_block"], edge[type="enqueues_script"]',
    style:    { 'width': 2.5, 'line-style': 'dotted', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6', 'target-arrow-shape': 'circle' },
  },
  // Registration edges — dashed green, triangle-backcurve arrow
  {
    selector: 'edge[type="registers"], edge[type="registers_rest"], edge[type="registers_shortcode"], edge[type="registers_page"], edge[type="registers_ajax"], edge[type="schedules_cron"], edge[type="registers_post_type"], edge[type="registers_taxonomy"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#4ADE80', 'target-arrow-color': '#4ADE80', 'target-arrow-shape': 'triangle-backcurve' },
  },
  // JS hook usage (file → js_hook) — dotted orange, triangle arrow
  {
    selector: 'edge[type="uses_hook"]',
    style:    { 'width': 2.5, 'line-style': 'dotted', 'line-color': '#FB923C', 'target-arrow-color': '#FB923C', 'target-arrow-shape': 'triangle' },
  },
  // JS apiFetch calls (file → js_api_call node) — solid green, triangle-backcurve arrow
  {
    selector: 'edge[type="js_api_call"]',
    style:    { 'width': 2.5, 'line-style': 'solid', 'line-color': '#4ADE80', 'target-arrow-color': '#4ADE80', 'target-arrow-shape': 'triangle-backcurve' },
  },
  // Cross-language JS→PHP edges — pink, circle arrow
  {
    selector: 'edge[type="calls_endpoint"]',
    style:    { 'width': 2.5, 'line-style': 'solid', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6', 'target-arrow-shape': 'circle' },
  },
  {
    selector: 'edge[type="calls_ajax_handler"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6', 'target-arrow-shape': 'circle' },
  },
  {
    selector: 'edge[type="js_block_matches_php"]',
    style:    { 'width': 2, 'line-style': 'dotted', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6', 'target-arrow-shape': 'circle' },
  },
  // Hook deregistration — dashed red, tee arrow
  {
    selector: 'edge[type="deregisters_hook"]',
    style:    { 'width': 2, 'line-style': 'dashed', 'line-color': '#F87171', 'target-arrow-color': '#F87171', 'target-arrow-shape': 'tee' },
  },
  // WordPress data store reads — solid amber, diamond arrow
  {
    selector: 'edge[type="reads_store"]',
    style:    { 'width': 2.5, 'line-style': 'solid', 'line-color': '#FCD34D', 'target-arrow-color': '#FCD34D', 'target-arrow-shape': 'diamond' },
  },
  // WordPress data store writes — dashed amber, diamond arrow
  {
    selector: 'edge[type="writes_store"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#FCD34D', 'target-arrow-color': '#FCD34D', 'target-arrow-shape': 'diamond' },
  },
];

// ── Stylesheet builder ────────────────────────────────────────────────────────

function buildStylesheet() {
  // One rule per node type — colour and shape are driven entirely by NODE_TYPES
  // in constants.js, so adding a new type here costs zero lines in this file.
  const typeRules = Object.entries(NODE_TYPES).map(([type, meta]) => ({
    selector: `node[type="${type}"]`,
    style: {
      'background-color': meta.color,
      'shape':            meta.shape,
    },
  }));

  return [
    // ── Base node ─────────────────────────────────────────────────────────
    {
      selector: 'node',
      style: {
        'label':             'data(label)',
        'text-valign':       'center',
        'text-halign':       'center',
        'color':             '#fff',
        'font-size':         '13px',
        'font-family':       'ui-monospace, monospace',
        'text-wrap':         'wrap',
        'text-max-width':    '160px',
        // width/height auto-size to the label via padding — 'label' was
        // deprecated in Cytoscape 3.33. Padding alone determines node size.
        'padding':           '14px',
        'min-width':         '60px',
        'min-height':        '28px',
        'border-width':      2,
        'border-color':      'rgba(255,255,255,0.25)',
        'background-color':  '#6B7280',
        'transition-property':  'background-color, border-color, opacity, text-opacity',
        'transition-duration':  '200ms',
      },
    },
    // ── Interface: dashed border signals "abstract contract" ─────────────
    {
      selector: 'node[type="interface"]',
      style: { 'border-style': 'dashed' },
    },
    // ── Trait: dotted border signals "mixin / partial" ───────────────────
    {
      selector: 'node[type="trait"]',
      style: { 'border-style': 'dotted' },
    },
    // ── Per-type colour + shape ───────────────────────────────────────────
    ...typeRules,
    // ── Library nodes: dotted border + muted opacity ─────────────────────
    {
      selector: 'node[?is_library]',
      style: {
        'border-style': 'dotted',
        'border-width':  2,
        'border-color': 'rgba(255,255,255,0.15)',
        'opacity':       0.7,
      },
    },
    // ── Compound namespace groups (PHP) ───────────────────────────────────
    {
      selector: 'node[type="namespace"]',
      style: {
        'background-color':  '#1E293B',
        'border-color':      '#334155',
        'border-width':      1,
        'label':             'data(label)',
        'text-valign':       'top',
        'text-halign':       'center',
        'font-size':         '11px',
        'font-family':       'ui-monospace, monospace',
        'color':             '#94A3B8',
        'padding':           '20px',
        'shape':             'round-rectangle',
      },
    },
    // ── Compound directory groups (JS) ────────────────────────────────────
    {
      selector: 'node[type="dir"]',
      style: {
        'background-color':  '#0F172A',
        'border-color':      '#1E293B',
        'border-width':      1,
        'label':             'data(label)',
        'text-valign':       'top',
        'font-size':         '10px',
        'font-family':       'ui-monospace, monospace',
        'color':             '#64748B',
        'padding':           '16px',
        'shape':             'round-rectangle',
      },
    },
    // ── Base edge ─────────────────────────────────────────────────────────
    {
      selector: 'edge',
      style: {
        'width':                   2.5,
        'line-color':              '#94A3B8',  // slate-400 — visible on dark bg
        'target-arrow-color':      '#94A3B8',
        'target-arrow-shape':      'triangle',
        'arrow-scale':             2,          // 2× default arrowhead size
        'curve-style':             'bezier',
        // Edge labels are hidden by default — they create visual noise at
        // normal zoom. They become visible when an edge is selected.
        'label':                   '',
        'font-size':               '10px',
        'color':                   '#94A3B8',
        'text-rotation':           'autorotate',
        'text-margin-y':           '-8px',
        'text-background-color':   '#1e293b',
        'text-background-opacity': 0.85,
        'text-background-padding': '3px',
        'text-background-shape':   'roundrectangle',
      },
    },
    // ── Edge selected: reveal label, thicken stroke ───────────────────────
    {
      selector: 'edge:selected',
      style: { 'label': 'data(label)', 'width': 3.5 },
    },
    // ── Per-type edge styles ──────────────────────────────────────────────
    ...EDGE_STYLES,
    // ── Dim non-focused elements during hover ─────────────────────────────
    {
      selector: 'node.dimmed',
      style: { 'opacity': 0.15 },
    },
    {
      selector: 'edge.dimmed',
      style: { 'opacity': 0.05 },
    },
    // ── Edge view-mode filter — hide edges not in the active mode ─────────
    {
      selector: 'edge.view-hidden',
      style: { 'display': 'none' },
    },
    // ── View-mode ghost dim — nodes with no visible edges in filtered modes ─
    // Distinct from hover `.dimmed` (0.15) — ghost dim is intentional, not transient.
    // Labels are hidden (text-opacity:0) so the ghosted node doesn't add text noise.
    {
      selector: 'node.view-dimmed',
      style: { 'opacity': 0.12, 'text-opacity': 0 },
    },
    // ── Circular dependency cycle edges — red glow ────────────────────────
    {
      selector: 'edge.edge-cycle',
      style: {
        'line-color':         '#EF4444',
        'target-arrow-color': '#EF4444',
        'width':              4,
        'z-index':            10,
        'overlay-color':      '#EF4444',
        'overlay-padding':    3,
        'overlay-opacity':    0.15,
      },
    },
    {
      selector: 'node.node-cycle',
      style: {
        'border-color': '#EF4444',
        'border-width': 3,
        'z-index':      10,
      },
    },
    // ── Selection / highlight ring ────────────────────────────────────────
    {
      selector: 'node:selected',
      style: { 'border-width': 3, 'border-color': '#FBBF24' },
    },
    {
      selector: 'node.highlighted',
      style: { 'border-color': '#FBBF24', 'border-width': 3 },
    },
  ];
}

// ── Cytoscape initialisation ──────────────────────────────────────────────────

/**
 * Create and return a configured Cytoscape instance.
 *
 * The WebGL renderer is enabled for graphs with >500 elements, where it
 * delivers a dramatic FPS improvement (20 FPS → 100+ FPS on M-series Macs
 * per Cytoscape.js Jan 2025 benchmarks). It supports bezier edges and all
 * node styles used here. Falls back to canvas transparently if unavailable.
 *
 * @param {HTMLElement}   container          - The #cy DOM element.
 * @param {Array}         elements           - Cytoscape elements array.
 * @param {Function}      onNodeClick        - Called with node data on tap.
 * @param {Function}      onNodeHover        - Called with (data, pos) on mouseover.
 * @param {Function}      onNodeDoubleClick  - Called with node data on double-tap.
 * @returns {cytoscape.Core}
 */
export function initCytoscape(container, elements, onNodeClick, onNodeHover, onNodeDoubleClick) {
  // Register layout plugins (idempotent — safe to call multiple times)
  if (window.cytoscapeDagre)          cytoscape.use(window.cytoscapeDagre);
  if (window.cytoscapeFcose)          cytoscape.use(window.cytoscapeFcose);
  if (window.cytoscapeExpandCollapse) cytoscape.use(window.cytoscapeExpandCollapse);

  // Use WebGL renderer for graphs above the threshold where canvas starts
  // dropping frames. Both renderers produce identical visual output.
  const useWebGL   = elements.length > 500;
  const rendererOpts = useWebGL ? { renderer: { name: 'canvas', webgl: true } } : {};

  const cy = cytoscape({
    container,
    elements,
    style:           buildStylesheet(),
    layout:          { name: 'preset' },  // app.js applies the auto-selected layout after init
    minZoom:         0.05,
    maxZoom:         4,
    wheelSensitivity: 0.3,
    ...rendererOpts,
  });

  // Honour prefers-reduced-motion: disable CSS transitions on nodes
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    cy.style().selector('node').style('transition-duration', '0ms').update();
  }

  // ── Interaction handlers ─────────────────────────────────────────────────

  // Single tap: open sidebar
  cy.on('tap', 'node', (evt) => {
    onNodeClick(evt.target.data());
  });

  // Hover: dim everything except the hovered node + its 1-hop neighbourhood
  cy.on('mouseover', 'node', (evt) => {
    const node = evt.target;
    cy.elements().addClass('dimmed');
    node.removeClass('dimmed');
    node.connectedEdges().removeClass('dimmed');
    node.connectedEdges().connectedNodes().removeClass('dimmed');
    onNodeHover(node.data(), node.renderedPosition());
  });

  cy.on('mouseout', 'node', () => {
    cy.elements().removeClass('dimmed');
    onNodeHover(null, null);
  });

  // Double-tap: zoom into the node's neighbourhood
  cy.on('dbltap', 'node', (evt) => {
    const node = evt.target;
    cy.fit(node.closedNeighborhood(), 60);
    onNodeDoubleClick(node.data());
  });

  // Click on canvas background: clear all highlights
  cy.on('tap', (evt) => {
    if (evt.target === cy) {
      cy.elements().removeClass('dimmed highlighted');
    }
  });

  return cy;
}
