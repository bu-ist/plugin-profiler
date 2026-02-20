export const LAYOUTS = {
  dagre: {
    name: 'dagre',
    rankDir: 'TB',
    padding: 40,
    nodeSep: 30,
    rankSep: 80,
    ranker: 'network-simplex',
    animate: true,
    animationDuration: 400,
  },
  cose: {
    name: 'cose',
    animate: true,
    animationDuration: 800,
    padding: 60,
    nodeRepulsion: 400000,
    idealEdgeLength: 100,
    edgeElasticity: 100,
    gravity: 25,
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
const LARGE_GRAPH = 1000;  // switch to grid above this
const SPARSE_GRAPH = 0.6;  // switch to cose below this density

export function pickLayout(nodeCount, density) {
  if (nodeCount > LARGE_GRAPH) return 'grid';
  if (nodeCount > 200 || density < SPARSE_GRAPH) return 'cose';
  return 'dagre';
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
