export const LAYOUTS = {
  dagre: {
    name: 'dagre',
    rankDir: 'LR',          // left-to-right reads better for dependency graphs
    padding: 40,
    nodeSep: 20,
    rankSep: 60,
    ranker: 'network-simplex',
    animate: true,
    animationDuration: 400,
  },
  cose: {
    name: 'cose',
    animate: true,
    animationDuration: 600,
    padding: 50,
    nodeRepulsion: 120000,   // was 400000 — lower = tighter clusters
    idealEdgeLength: 60,     // was 100 — shorter edges = more compact
    edgeElasticity: 80,
    gravity: 80,             // was 25 — higher = pulls graph to center
    numIter: 1000,
    initialTemp: 200,
    coolingFactor: 0.95,
    minTemp: 1.0,
  },
  breadthfirst: {
    name: 'breadthfirst',
    directed: true,
    animate: true,
    animationDuration: 400,
    padding: 50,
    spacingFactor: 1.6,
  },
  grid: {
    name: 'grid',
    animate: false,  // instant — grid is used for large graphs where animation would freeze
    padding: 50,
    avoidOverlap: true,
    avoidOverlapPadding: 10,
  },
};

// Thresholds
const LARGE_GRAPH  = 1000;  // switch to grid above this
const MEDIUM_GRAPH = 300;   // switch to cose above this
const SPARSE_GRAPH = 0.3;   // switch to cose below this density (very sparse = tree-like)

export function pickLayout(nodeCount, density) {
  if (nodeCount > LARGE_GRAPH)  return 'grid';
  if (nodeCount > MEDIUM_GRAPH) return 'cose';
  if (density < SPARSE_GRAPH)   return 'cose';
  return 'dagre';  // default: structured left-to-right hierarchy
}

export function applyLayout(cy, name) {
  // Disable animation for large graphs to prevent freezing
  const count  = cy.nodes().length;
  const config = { ...(LAYOUTS[name] || LAYOUTS.dagre) };
  if (count > LARGE_GRAPH) {
    config.animate = false;
  }
  cy.layout(config).run();
}
