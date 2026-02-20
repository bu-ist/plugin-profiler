export const LAYOUTS = {
  dagre: {
    name: 'dagre',
    // TB = top-to-bottom ranks; hierarchy flows downward filling viewport height.
    // With many nodes, TB spreads them across multiple columns naturally and
    // avoids the single-row band that LR produces for wide dependency graphs.
    rankDir: 'TB',
    padding: 40,
    nodeSep: 30,   // horizontal gap between nodes in the same rank
    rankSep: 80,   // vertical gap between ranks (hierarchy levels)
    ranker: 'network-simplex',
    animate: true,
    animationDuration: 400,
  },
  cose: {
    name: 'cose',
    animate: true,
    animationDuration: 800,
    padding: 60,
    // High repulsion spreads isolated nodes across the canvas instead of
    // collapsing them into a band (critical when ~40% of nodes have no edges).
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
    animate: true,
    animationDuration: 400,
    padding: 50,
    rows: undefined,
    avoidOverlap: true,
    avoidOverlapPadding: 20,
  },
};

export function applyLayout(cy, name) {
  const config = LAYOUTS[name] || LAYOUTS.dagre;
  cy.layout(config).run();
}
