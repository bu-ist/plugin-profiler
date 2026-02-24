/**
 * constants.js — Single source of truth for all node type display metadata.
 *
 * Every file that needs type information (graph.js, sidebar.js, search.js)
 * imports from here.  Adding a new node type requires editing only this one file.
 *
 * Each entry carries three properties:
 *   color  — Cytoscape background-color (hex string)
 *   shape  — Cytoscape node shape name
 *   badge  — Tailwind CSS classes for the sidebar type badge and search filter button
 *
 * Colour families convey category at a glance:
 *   Blue   — PHP structure (class, interface, trait)
 *   Teal   — PHP behaviour (function, method)
 *   Orange — WordPress hooks
 *   Green  — WordPress integration points (endpoints, AJAX, shortcodes…)
 *   Purple — Data layer
 *   Red    — Outbound HTTP
 *   Gray   — File system
 *   Pink   — Gutenberg / WordPress JS
 *   Cyan   — React components
 *   Violet — React hooks
 *   Rose   — Network fetch calls
 *   Amber  — WordPress data stores (@wordpress/data)
 *   Slate  — Compound group nodes (recede behind their children)
 */

/**
 * @typedef {{ color: string, shape: string, badge: string }} NodeTypeMeta
 * @type {Record<string, NodeTypeMeta>}
 */
export const NODE_TYPES = {

  // ── PHP structure — blue ──────────────────────────────────────────────────
  // bg-blue-700 = 6.5:1 ✅  bg-blue-600 = 5.1:1 ✅  bg-blue-300 + text-gray-800 = 8.2:1 ✅
  class:            { color: '#3B82F6', shape: 'round-rectangle', badge: 'bg-blue-700' },
  interface:        { color: '#3B82F6', shape: 'round-rectangle', badge: 'bg-blue-600' },
  trait:            { color: '#3B82F6', shape: 'round-rectangle', badge: 'bg-blue-300 text-gray-800' },

  // ── PHP behaviour — teal ──────────────────────────────────────────────────
  // bg-teal-700 = 5.6:1 ✅  bg-teal-800 = 7.5:1 ✅
  function:         { color: '#14B8A6', shape: 'roundrectangle',  badge: 'bg-teal-700' },
  method:           { color: '#14B8A6', shape: 'roundrectangle',  badge: 'bg-teal-800' },

  // ── WordPress hooks — orange ──────────────────────────────────────────────
  // bg-orange-700 = 5.1:1 ✅  bg-orange-800 = 7.2:1 ✅
  hook:             { color: '#F97316', shape: 'diamond',         badge: 'bg-orange-700' },
  js_hook:          { color: '#F97316', shape: 'diamond',         badge: 'bg-orange-800' },

  // ── WordPress integration points — green ──────────────────────────────────
  // bg-green-700 = 5.1:1 ✅  bg-green-800 = 7.3:1 ✅  bg-green-300 + text-gray-800 = 10.6:1 ✅
  rest_endpoint:    { color: '#22C55E', shape: 'hexagon',         badge: 'bg-green-700' },
  ajax_handler:     { color: '#22C55E', shape: 'hexagon',         badge: 'bg-green-800' },
  shortcode:        { color: '#22C55E', shape: 'tag',             badge: 'bg-green-300 text-gray-800' },
  admin_page:       { color: '#22C55E', shape: 'rectangle',       badge: 'bg-green-700' },
  cron_job:         { color: '#22C55E', shape: 'ellipse',         badge: 'bg-green-800' },
  post_type:        { color: '#22C55E', shape: 'barrel',          badge: 'bg-green-800' },
  taxonomy:         { color: '#22C55E', shape: 'barrel',          badge: 'bg-green-800' },
  js_api_call:      { color: '#22C55E', shape: 'ellipse',         badge: 'bg-green-700' },

  // ── Data layer — purple ───────────────────────────────────────────────────
  // bg-purple-700 = 8.3:1 ✅
  data_source:      { color: '#A855F7', shape: 'barrel',          badge: 'bg-purple-700' },

  // ── Outbound HTTP — red ───────────────────────────────────────────────────
  // bg-red-700 = 6.0:1 ✅
  http_call:        { color: '#EF4444', shape: 'ellipse',         badge: 'bg-red-700' },

  // ── File system — gray ────────────────────────────────────────────────────
  // bg-gray-600 = 7.3:1 ✅
  file:             { color: '#6B7280', shape: 'rectangle',       badge: 'bg-gray-600' },

  // ── Gutenberg — pink ──────────────────────────────────────────────────────
  // bg-pink-700 = 6.1:1 ✅
  gutenberg_block:  { color: '#EC4899', shape: 'round-rectangle', badge: 'bg-pink-700' },

  // ── JS equivalents — same families as PHP for visual continuity ───────────
  js_function:      { color: '#14B8A6', shape: 'roundrectangle',  badge: 'bg-teal-700' },
  js_class:         { color: '#3B82F6', shape: 'round-rectangle', badge: 'bg-blue-700' },

  // ── React / modern frontend — distinct cyan / violet / rose ───────────────
  // bg-cyan-700 = 5.4:1 ✅  bg-violet-700 = 9.1:1 ✅  bg-rose-700 = 6.3:1 ✅
  react_component:  { color: '#06B6D4', shape: 'round-rectangle', badge: 'bg-cyan-700' },
  react_hook:       { color: '#8B5CF6', shape: 'diamond',         badge: 'bg-violet-700' },
  fetch_call:       { color: '#F43F5E', shape: 'ellipse',         badge: 'bg-rose-700' },
  axios_call:       { color: '#F43F5E', shape: 'ellipse',         badge: 'bg-rose-700' },

  // ── WordPress data stores — amber ──────────────────────────────────────────
  // bg-amber-700 = 4.9:1 ✅
  wp_store:         { color: '#F59E0B', shape: 'barrel',          badge: 'bg-amber-700' },

  // ── Compound group nodes — neutral slate (recede behind their children) ───
  namespace:        { color: '#1E293B', shape: 'round-rectangle', badge: 'bg-slate-600' },
  dir:              { color: '#0F172A', shape: 'round-rectangle', badge: 'bg-slate-700' },
};

/**
 * EDGE_VIEW_MODES — preset edge-type filters for the view-mode toolbar buttons.
 *
 * "requirements" shows code-dependency edges (inheritance, composition, file
 *               includes) — what the code requires from other code.
 * "data"        shows runtime data-flow edges (hooks, DB operations, REST,
 *               AJAX, cross-language calls) — what the plugin does at runtime.
 * "all"         shows everything (null set = no filtering).
 *
 * @type {Record<string, { label: string, edges: Set<string>|null }>}
 */
export const EDGE_VIEW_MODES = {
  all: {
    label: 'All',
    edges: null,
  },
  requirements: {
    label: 'Requirements',
    // Structural / load-time dependency edges: what code must exist for this
    // code to run? Inheritance, composition, function calls, file includes.
    edges: new Set([
      'extends', 'implements', 'uses_trait',
      'instantiates', 'calls',
      'includes', 'defines', 'has_method',
      'defines_component',           // React component structural definition
      'imports',                     // JS module imports between plugin files
    ]),
  },
  data: {
    label: 'Data',
    // Runtime / event-driven edges: what happens when WordPress fires events
    // and data moves through the system?
    edges: new Set([
      // WordPress hook system (register, trigger, remove)
      'registers_hook', 'triggers_hook', 'triggers_handler', 'js_registers_hook',
      'deregisters_hook',
      // Database / storage
      'reads_data', 'writes_data',
      // WordPress data store (@wordpress/data)
      'reads_store', 'writes_store',
      // WordPress runtime registrations (all happen at init / admin_init)
      'registers',
      'registers_rest', 'registers_shortcode', 'registers_page', 'registers_ajax',
      'registers_post_type', 'registers_taxonomy',
      'schedules_cron',
      // Outbound HTTP (PHP: http_request, JS: http_call)
      'http_request', 'http_call',
      // Block rendering and asset enqueueing
      'renders_block', 'registers_block', 'enqueues_script',
      // JS WordPress hooks and API calls
      'uses_hook', 'js_api_call',
      // Cross-language JS → PHP calls
      'calls_endpoint', 'calls_ajax_handler', 'js_block_matches_php',
    ]),
  },
  web: {
    label: 'Web',
    // User-facing surface: what renders in the browser, what the frontend JS
    // calls, what admin pages and shortcodes exist. Focuses on the bridge
    // between frontend code and the WordPress backend.
    edges: new Set([
      // Block rendering and asset enqueueing
      'renders_block', 'registers_block', 'enqueues_script',
      // Frontend registrations (shortcodes, admin pages, AJAX, REST)
      'registers_shortcode', 'registers_page', 'registers_ajax', 'registers_rest',
      // Cross-language JS → PHP calls (frontend consuming backend)
      'calls_endpoint', 'calls_ajax_handler', 'js_block_matches_php',
      // WordPress data stores (editor UI)
      'reads_store', 'writes_store',
      // JS hooks (frontend-side hook system)
      'js_registers_hook', 'uses_hook',
      // JS module imports (frontend dependency chain)
      'imports',
    ]),
  },
};

/**
 * EDGE_TYPE_META — per-edge-type visual metadata for the legend panel.
 *
 * Each entry records the colour, line-style, arrow-shape, colour-family name,
 * and which view modes the edge belongs to.  This is the single source of truth
 * consumed by the legend renderer; graph.js EDGE_STYLES is the Cytoscape-
 * specific counterpart (kept separate because selectors differ).
 *
 * `modes` is an array so edges can appear in multiple view modes (e.g.
 * registers_rest belongs to both "data" and "web").
 *
 * @type {Record<string, { color: string, lineStyle: string, arrowShape: string, family: string, modes: string[] }>}
 */
export const EDGE_TYPE_META = {
  // ── Requirements (structural) ───────────────────────────────────────────
  extends:             { color: '#60A5FA', lineStyle: 'solid',  arrowShape: 'vee',               family: 'Inheritance',   modes: ['requirements'] },
  implements:          { color: '#60A5FA', lineStyle: 'solid',  arrowShape: 'vee',               family: 'Inheritance',   modes: ['requirements'] },
  uses_trait:          { color: '#60A5FA', lineStyle: 'solid',  arrowShape: 'vee',               family: 'Inheritance',   modes: ['requirements'] },
  instantiates:        { color: '#2DD4BF', lineStyle: 'dotted', arrowShape: 'diamond',           family: 'Instantiation', modes: ['requirements'] },
  calls:               { color: '#94A3B8', lineStyle: 'solid',  arrowShape: 'triangle',          family: 'Calls',         modes: ['requirements'] },
  has_method:          { color: '#94A3B8', lineStyle: 'dotted', arrowShape: 'triangle',          family: 'Structure',     modes: ['requirements'] },
  includes:            { color: '#94A3B8', lineStyle: 'dashed', arrowShape: 'triangle',          family: 'Structure',     modes: ['requirements'] },
  defines:             { color: '#94A3B8', lineStyle: 'dotted', arrowShape: 'triangle',          family: 'Structure',     modes: ['requirements'] },
  defines_component:   { color: '#06B6D4', lineStyle: 'solid',  arrowShape: 'triangle',          family: 'Structure',     modes: ['requirements'] },
  imports:             { color: '#818CF8', lineStyle: 'dashed', arrowShape: 'chevron',           family: 'JS imports',    modes: ['requirements', 'web'] },

  // ── Data (runtime) ──────────────────────────────────────────────────────
  registers_hook:      { color: '#FB923C', lineStyle: 'dashed', arrowShape: 'triangle',          family: 'Hooks',         modes: ['data'] },
  triggers_hook:       { color: '#FB923C', lineStyle: 'dashed', arrowShape: 'triangle',          family: 'Hooks',         modes: ['data'] },
  triggers_handler:    { color: '#FB923C', lineStyle: 'solid',  arrowShape: 'triangle',          family: 'Hooks',         modes: ['data'] },
  js_registers_hook:   { color: '#FB923C', lineStyle: 'dashed', arrowShape: 'triangle',          family: 'Hooks',         modes: ['data', 'web'] },
  uses_hook:           { color: '#FB923C', lineStyle: 'dotted', arrowShape: 'triangle',          family: 'Hooks',         modes: ['data', 'web'] },
  deregisters_hook:    { color: '#F87171', lineStyle: 'dashed', arrowShape: 'tee',               family: 'Deregister',    modes: ['data'] },
  reads_data:          { color: '#C084FC', lineStyle: 'solid',  arrowShape: 'square',            family: 'Data',          modes: ['data'] },
  writes_data:         { color: '#C084FC', lineStyle: 'dashed', arrowShape: 'square',            family: 'Data',          modes: ['data'] },
  reads_store:         { color: '#FCD34D', lineStyle: 'solid',  arrowShape: 'diamond',           family: 'WP store',      modes: ['data', 'web'] },
  writes_store:        { color: '#FCD34D', lineStyle: 'dashed', arrowShape: 'diamond',           family: 'WP store',      modes: ['data', 'web'] },
  http_request:        { color: '#F87171', lineStyle: 'solid',  arrowShape: 'tee',               family: 'HTTP',          modes: ['data'] },
  http_call:           { color: '#F87171', lineStyle: 'solid',  arrowShape: 'tee',               family: 'HTTP',          modes: ['data'] },
  renders_block:       { color: '#F472B6', lineStyle: 'dotted', arrowShape: 'circle',            family: 'Blocks',        modes: ['data', 'web'] },
  registers_block:     { color: '#F472B6', lineStyle: 'dashed', arrowShape: 'circle',            family: 'Blocks',        modes: ['data', 'web'] },
  enqueues_script:     { color: '#F472B6', lineStyle: 'dotted', arrowShape: 'circle',            family: 'Blocks',        modes: ['data', 'web'] },
  registers:           { color: '#4ADE80', lineStyle: 'dashed', arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data'] },
  registers_rest:      { color: '#4ADE80', lineStyle: 'dashed', arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data', 'web'] },
  registers_shortcode: { color: '#4ADE80', lineStyle: 'dashed', arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data', 'web'] },
  registers_page:      { color: '#4ADE80', lineStyle: 'dashed', arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data', 'web'] },
  registers_ajax:      { color: '#4ADE80', lineStyle: 'dashed', arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data', 'web'] },
  registers_post_type: { color: '#4ADE80', lineStyle: 'dashed', arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data'] },
  registers_taxonomy:  { color: '#4ADE80', lineStyle: 'dashed', arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data'] },
  schedules_cron:      { color: '#4ADE80', lineStyle: 'dashed', arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data'] },
  js_api_call:         { color: '#4ADE80', lineStyle: 'solid',  arrowShape: 'triangle-backcurve', family: 'Registration', modes: ['data'] },
  calls_endpoint:      { color: '#F472B6', lineStyle: 'solid',  arrowShape: 'circle',            family: 'Cross-lang',   modes: ['data', 'web'] },
  calls_ajax_handler:  { color: '#F472B6', lineStyle: 'dashed', arrowShape: 'circle',            family: 'Cross-lang',   modes: ['data', 'web'] },
  js_block_matches_php:{ color: '#F472B6', lineStyle: 'dotted', arrowShape: 'circle',            family: 'Cross-lang',   modes: ['data', 'web'] },
};

/** Return the Cytoscape fill colour for a node type (fallback: gray). */
export const nodeColor = (type) => NODE_TYPES[type]?.color ?? '#6B7280';

/** Return the Cytoscape shape for a node type (fallback: ellipse). */
export const nodeShape = (type) => NODE_TYPES[type]?.shape ?? 'ellipse';

/** Return the Tailwind badge/button class string for a node type (fallback: gray). */
export const nodeBadge = (type) => NODE_TYPES[type]?.badge ?? 'bg-gray-500';
