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
 *   Slate  — Compound group nodes (recede behind their children)
 */

/**
 * @typedef {{ color: string, shape: string, badge: string }} NodeTypeMeta
 * @type {Record<string, NodeTypeMeta>}
 */
export const NODE_TYPES = {

  // ── PHP structure — blue ──────────────────────────────────────────────────
  class:            { color: '#3B82F6', shape: 'round-rectangle', badge: 'bg-blue-500' },
  interface:        { color: '#3B82F6', shape: 'round-rectangle', badge: 'bg-blue-400' },
  trait:            { color: '#3B82F6', shape: 'round-rectangle', badge: 'bg-blue-300 text-gray-800' },

  // ── PHP behaviour — teal ──────────────────────────────────────────────────
  function:         { color: '#14B8A6', shape: 'roundrectangle',  badge: 'bg-teal-500' },
  method:           { color: '#14B8A6', shape: 'roundrectangle',  badge: 'bg-teal-400' },

  // ── WordPress hooks — orange ──────────────────────────────────────────────
  hook:             { color: '#F97316', shape: 'diamond',         badge: 'bg-orange-500' },
  js_hook:          { color: '#F97316', shape: 'diamond',         badge: 'bg-orange-400' },

  // ── WordPress integration points — green ──────────────────────────────────
  rest_endpoint:    { color: '#22C55E', shape: 'hexagon',         badge: 'bg-green-500' },
  ajax_handler:     { color: '#22C55E', shape: 'hexagon',         badge: 'bg-green-400' },
  shortcode:        { color: '#22C55E', shape: 'tag',             badge: 'bg-green-300 text-gray-800' },
  admin_page:       { color: '#22C55E', shape: 'rectangle',       badge: 'bg-green-600' },
  cron_job:         { color: '#22C55E', shape: 'ellipse',         badge: 'bg-green-700' },
  post_type:        { color: '#22C55E', shape: 'barrel',          badge: 'bg-green-800' },
  taxonomy:         { color: '#22C55E', shape: 'barrel',          badge: 'bg-green-800' },
  js_api_call:      { color: '#22C55E', shape: 'ellipse',         badge: 'bg-green-500' },

  // ── Data layer — purple ───────────────────────────────────────────────────
  data_source:      { color: '#A855F7', shape: 'barrel',          badge: 'bg-purple-500' },

  // ── Outbound HTTP — red ───────────────────────────────────────────────────
  http_call:        { color: '#EF4444', shape: 'ellipse',         badge: 'bg-red-500' },

  // ── File system — gray ────────────────────────────────────────────────────
  file:             { color: '#6B7280', shape: 'rectangle',       badge: 'bg-gray-500' },

  // ── Gutenberg — pink ──────────────────────────────────────────────────────
  gutenberg_block:  { color: '#EC4899', shape: 'round-rectangle', badge: 'bg-pink-500' },

  // ── JS equivalents — same families as PHP for visual continuity ───────────
  js_function:      { color: '#14B8A6', shape: 'roundrectangle',  badge: 'bg-teal-500' },
  js_class:         { color: '#3B82F6', shape: 'round-rectangle', badge: 'bg-blue-500' },

  // ── React / modern frontend — distinct cyan / violet / rose ───────────────
  react_component:  { color: '#06B6D4', shape: 'round-rectangle', badge: 'bg-cyan-500' },
  react_hook:       { color: '#8B5CF6', shape: 'diamond',         badge: 'bg-violet-500' },
  fetch_call:       { color: '#F43F5E', shape: 'ellipse',         badge: 'bg-rose-500' },
  axios_call:       { color: '#F43F5E', shape: 'ellipse',         badge: 'bg-rose-500' },

  // ── Compound group nodes — neutral slate (recede behind their children) ───
  namespace:        { color: '#1E293B', shape: 'round-rectangle', badge: 'bg-slate-600' },
  dir:              { color: '#0F172A', shape: 'round-rectangle', badge: 'bg-slate-700' },
};

/** Return the Cytoscape fill colour for a node type (fallback: gray). */
export const nodeColor = (type) => NODE_TYPES[type]?.color ?? '#6B7280';

/** Return the Cytoscape shape for a node type (fallback: ellipse). */
export const nodeShape = (type) => NODE_TYPES[type]?.shape ?? 'ellipse';

/** Return the Tailwind badge/button class string for a node type (fallback: gray). */
export const nodeBadge = (type) => NODE_TYPES[type]?.badge ?? 'bg-gray-500';
