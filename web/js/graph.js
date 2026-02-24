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
  // Inheritance — solid blue (blue-400: bright, readable on dark bg)
  {
    selector: 'edge[type="extends"], edge[type="implements"], edge[type="uses_trait"]',
    style:    { 'line-style': 'solid', 'width': 2.5, 'line-color': '#60A5FA', 'target-arrow-color': '#60A5FA' },
  },
  // Instantiation — dotted teal (weaker dependency than inheritance)
  {
    selector: 'edge[type="instantiates"]',
    style:    { 'line-style': 'dotted', 'width': 2, 'line-color': '#2DD4BF', 'target-arrow-color': '#2DD4BF' },
  },
  // JS module imports — dashed slate-blue (softer than class edges; JS file-to-file)
  {
    selector: 'edge[type="imports"]',
    style:    { 'line-style': 'dashed', 'width': 1.5, 'line-color': '#818CF8', 'target-arrow-color': '#818CF8' },
  },
  // WordPress hooks — dashed orange (orange-400)
  {
    selector: 'edge[type="registers_hook"], edge[type="triggers_hook"], edge[type="js_registers_hook"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#FB923C', 'target-arrow-color': '#FB923C' },
  },
  // Hook trigger to handler — solid orange (orange-400)
  {
    selector: 'edge[type="triggers_handler"]',
    style:    { 'width': 2.5, 'line-style': 'solid', 'line-color': '#FB923C', 'target-arrow-color': '#FB923C' },
  },
  // Data reads — solid purple (purple-400)
  {
    selector: 'edge[type="reads_data"]',
    style:    { 'width': 2.5, 'line-color': '#C084FC', 'target-arrow-color': '#C084FC' },
  },
  // Data writes — dashed purple (writes are "heavier" than reads)
  {
    selector: 'edge[type="writes_data"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#C084FC', 'target-arrow-color': '#C084FC' },
  },
  // Outbound HTTP — red (red-400)
  {
    selector: 'edge[type="http_request"]',
    style:    { 'width': 2.5, 'line-color': '#F87171', 'target-arrow-color': '#F87171' },
  },
  // Block rendering and asset enqueueing — dotted pink (pink-400)
  {
    selector: 'edge[type="renders_block"], edge[type="enqueues_script"]',
    style:    { 'width': 2.5, 'line-style': 'dotted', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6' },
  },
  // Registration edges — dashed green (green-400)
  {
    selector: 'edge[type="registers"], edge[type="registers_rest"], edge[type="registers_shortcode"], edge[type="registers_page"], edge[type="registers_ajax"], edge[type="schedules_cron"], edge[type="registers_post_type"], edge[type="registers_taxonomy"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#4ADE80', 'target-arrow-color': '#4ADE80' },
  },
  // JS hook usage (file → js_hook) — same orange family as PHP hooks
  {
    selector: 'edge[type="uses_hook"]',
    style:    { 'width': 2.5, 'line-style': 'dotted', 'line-color': '#FB923C', 'target-arrow-color': '#FB923C' },
  },
  // JS apiFetch calls (file → js_api_call node) — green, same REST family
  {
    selector: 'edge[type="js_api_call"]',
    style:    { 'width': 2.5, 'line-style': 'solid', 'line-color': '#4ADE80', 'target-arrow-color': '#4ADE80' },
  },
  // Cross-language JS→PHP edges — solid/dashed pink (the tool's unique signal)
  {
    selector: 'edge[type="calls_endpoint"]',
    style:    { 'width': 2.5, 'line-style': 'solid', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6' },
  },
  {
    selector: 'edge[type="calls_ajax_handler"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6' },
  },
  {
    selector: 'edge[type="js_block_matches_php"]',
    style:    { 'width': 2, 'line-style': 'dotted', 'line-color': '#F472B6', 'target-arrow-color': '#F472B6' },
  },
  // Hook deregistration — dashed red (signals removal / teardown)
  {
    selector: 'edge[type="deregisters_hook"]',
    style:    { 'width': 2, 'line-style': 'dashed', 'line-color': '#F87171', 'target-arrow-color': '#F87171' },
  },
  // WordPress data store reads — solid amber (reads_store like reads_data but amber)
  {
    selector: 'edge[type="reads_store"]',
    style:    { 'width': 2.5, 'line-style': 'solid', 'line-color': '#FCD34D', 'target-arrow-color': '#FCD34D' },
  },
  // WordPress data store writes — dashed amber (writes_store like writes_data but amber)
  {
    selector: 'edge[type="writes_store"]',
    style:    { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#FCD34D', 'target-arrow-color': '#FCD34D' },
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
        'transition-property':  'background-color, border-color, opacity',
        'transition-duration':  '150ms',
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
