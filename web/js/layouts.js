export const LAYOUTS = {
  dagre: {
    name: 'dagre',
    rankDir: 'TB',
    padding: 30,
    nodeSep: 40,
    rankSep: 60,
    animate: true,
    animationDuration: 300,
  },
  cose: {
    name: 'cose',
    animate: true,
    animationDuration: 500,
    padding: 30,
    nodeRepulsion: 8000,
    idealEdgeLength: 100,
    gravity: 0.25,
  },
  breadthfirst: {
    name: 'breadthfirst',
    directed: true,
    animate: true,
    animationDuration: 300,
    padding: 30,
    spacingFactor: 1.2,
  },
  grid: {
    name: 'grid',
    animate: true,
    animationDuration: 300,
    padding: 30,
    rows: undefined,
  },
};

export function applyLayout(cy, name) {
  const config = LAYOUTS[name] || LAYOUTS.dagre;
  cy.layout(config).run();
}
