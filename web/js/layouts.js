/**
 * Layout configurations for Cytoscape.js.
 *
 * Layout selection rationale (from research):
 *
 * fCoSE — "Fast Compound Spring Embedder" (iVis@Bilkent).
 *   ~2× faster than classic CoSE, supports compound (parent) nodes for namespace
 *   grouping, and handles disconnected components well via packComponents.
 *   Best default for the wide variety of plugin graph topologies.
 *
 * Dagre — Directed acyclic graph layout (Sugiyama algorithm).
 *   Optimal for strict tree-like call hierarchies (e.g., PHP class inheritance
 *   chains), but renders flat star topologies as a single column. Offered as a
 *   manual alternative.
 *
 * Breadthfirst — BFS tree layout.
 *   Good for simple tree structures where a clear root exists.
 *
 * Grid — Deterministic grid. Instant, no physics. Used for very large graphs
 *   where any physics layout would freeze the browser.
 *
 * pickLayout() auto-selects based on true graph density (edges / max-possible-
 * edges, range 0–1). Typical values:
 *   - Pure React app (star topology):          ~0.01 → fCoSE
 *   - WP plugin (mixed hooks + classes):       ~0.02–0.06 → fCoSE
 *   - Extremely dense dependency graph:        ~0.08+ → dagre
 */

export const LAYOUTS = {

  // ── fCoSE: default for almost all graphs ──────────────────────────────────
  fcose: {
    name: 'fcose',
    // 'draft'   = very fast, lower quality  (good for >500 nodes)
    // 'default' = balanced                  (our standard)
    // 'proof'   = slow, highest quality     (for screenshots / exports)
    quality:                   'default',
    animate:                   true,
    animationDuration:         500,
    padding:                   50,
    nodeDimensionsIncludeLabels: true,
    uniformNodeDimensions:     false,
    packComponents:            true,    // pack disconnected subgraphs neatly
    nodeRepulsion:             4500,
    idealEdgeLength:           80,
    edgeElasticity:            0.45,
    nestingFactor:             0.1,
    gravity:                   0.25,
    numIter:                   2500,
    gravityRange:              3.8,
  },

  // ── Dagre: explicit hierarchy mode ────────────────────────────────────────
  dagre: {
    name:            'dagre',
    rankDir:         'LR',             // left-to-right reads naturally for imports
    padding:         40,
    nodeSep:         20,
    rankSep:         60,
    ranker:          'network-simplex',
    animate:         true,
    animationDuration: 400,
  },

  // ── Breadthfirst: BFS tree ─────────────────────────────────────────────────
  breadthfirst: {
    name:            'breadthfirst',
    directed:        true,
    animate:         true,
    animationDuration: 400,
    padding:         50,
    spacingFactor:   1.6,
  },

  // ── Grid: instant, no physics — for very large graphs ─────────────────────
  grid: {
    name:            'grid',
    animate:         false,            // instant — grid is the last-resort fallback
    padding:         50,
    avoidOverlap:    true,
    avoidOverlapPadding: 10,
  },
};

// ── Layout auto-selection thresholds ──────────────────────────────────────────

/** Switch to grid above this node count — physics layouts freeze the browser. */
const LARGE_GRAPH = 1000;

/** Switch to fCoSE above this count — dagre becomes illegible at medium scale. */
const MEDIUM_GRAPH = 300;

/**
 * True graph density cutoff (0–1 range: edges / max-possible-edges).
 * Below this value, the graph is sparse enough that fCoSE clusters better
 * than dagre's forced hierarchy. Typical values:
 *   - React star graph: ~0.012   → well below, picks fCoSE ✓
 *   - Mixed WP plugin:  ~0.02–0.06 → below, picks fCoSE ✓
 *   - Dense DAG:        ~0.10+   → above, picks dagre ✓
 */
const SPARSE_GRAPH = 0.08;

/**
 * Auto-select the best layout for a given graph.
 * Called once after data loads; the user can override via the dropdown.
 *
 * @param {number} nodeCount - Rendered node count.
 * @param {number} density   - True graph density: edges / (n*(n-1)/2).
 * @returns {'grid'|'fcose'|'dagre'} Layout name key from LAYOUTS.
 */
export function pickLayout(nodeCount, density) {
  if (nodeCount > LARGE_GRAPH)  return 'grid';
  if (nodeCount > MEDIUM_GRAPH) return 'fcose';
  if (density < SPARSE_GRAPH)   return 'fcose';
  return 'dagre';
}

/**
 * Apply a named layout to the Cytoscape instance.
 * Animation is disabled for large graphs to prevent frame-rate collapse.
 *
 * @param {cytoscape.Core} cy   - The Cytoscape instance.
 * @param {string}         name - Key from LAYOUTS (or any built-in layout name).
 */
export function applyLayout(cy, name) {
  const count  = cy.nodes().length;
  const config = { ...(LAYOUTS[name] || LAYOUTS.fcose) };
  if (count > LARGE_GRAPH) {
    config.animate = false;
  }
  cy.layout(config).run();
}
