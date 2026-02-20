import { LAYOUTS } from './layouts.js';

const NODE_COLORS = {
  class:           '#3B82F6',
  interface:       '#3B82F6',
  trait:           '#3B82F6',
  function:        '#14B8A6',
  method:          '#14B8A6',
  hook:            '#F97316',
  js_hook:         '#F97316',
  rest_endpoint:   '#22C55E',
  ajax_handler:    '#22C55E',
  shortcode:       '#22C55E',
  admin_page:      '#22C55E',
  cron_job:        '#22C55E',
  post_type:       '#22C55E',
  taxonomy:        '#22C55E',
  data_source:     '#A855F7',
  http_call:       '#EF4444',
  file:            '#6B7280',
  gutenberg_block: '#EC4899',
  js_api_call:     '#22C55E',
  js_function:     '#14B8A6',
  js_class:        '#3B82F6',
};

const NODE_SHAPES = {
  class:           'round-rectangle',
  interface:       'round-rectangle',
  trait:           'round-rectangle',
  function:        'roundrectangle',
  method:          'roundrectangle',
  hook:            'diamond',
  js_hook:         'diamond',
  rest_endpoint:   'hexagon',
  ajax_handler:    'hexagon',
  shortcode:       'tag',
  admin_page:      'rectangle',
  cron_job:        'ellipse',
  post_type:       'barrel',
  taxonomy:        'barrel',
  data_source:     'barrel',
  http_call:       'ellipse',
  file:            'rectangle',
  gutenberg_block: 'round-rectangle',
  js_api_call:     'ellipse',
  js_function:     'roundrectangle',
  js_class:        'round-rectangle',
};

function buildStylesheet() {
  const typeSelectors = Object.entries(NODE_COLORS).map(([type, color]) => ({
    selector: `node[type="${type}"]`,
    style: {
      'background-color': color,
      'shape': NODE_SHAPES[type] || 'ellipse',
    },
  }));

  return [
    {
      selector: 'node',
      style: {
        'label': 'data(label)',
        'text-valign': 'center',
        'text-halign': 'center',
        'color': '#fff',
        'font-size': '11px',
        'font-family': 'ui-monospace, monospace',
        'text-wrap': 'wrap',
        'text-max-width': '130px',
        'width': 'label',
        'height': 'label',
        'padding': '10px',
        'border-width': 2,
        'border-color': 'rgba(255,255,255,0.3)',
        'background-color': '#6B7280',
        'transition-property': 'background-color, border-color, opacity',
        'transition-duration': '150ms',
      },
    },
    {
      selector: 'node[type="interface"]',
      style: { 'border-style': 'dashed' },
    },
    {
      selector: 'node[type="trait"]',
      style: { 'border-style': 'dotted' },
    },
    ...typeSelectors,
    {
      selector: 'edge',
      style: {
        'width': 1.5,
        'line-color': '#94A3B8',
        'target-arrow-color': '#94A3B8',
        'target-arrow-shape': 'triangle',
        'curve-style': 'bezier',
        'label': 'data(label)',
        'font-size': '9px',
        'color': '#64748B',
        'text-rotation': 'autorotate',
        'text-margin-y': '-8px',
      },
    },
    {
      selector: 'edge[type="extends"], edge[type="implements"]',
      style: { 'line-style': 'solid', 'width': 2, 'line-color': '#3B82F6', 'target-arrow-color': '#3B82F6' },
    },
    {
      selector: 'edge[type="registers_hook"], edge[type="triggers_hook"], edge[type="js_registers_hook"]',
      style: { 'line-style': 'dashed', 'line-color': '#F97316', 'target-arrow-color': '#F97316' },
    },
    {
      selector: 'edge[type="reads_data"]',
      style: { 'width': 2.5, 'line-color': '#A855F7', 'target-arrow-color': '#A855F7' },
    },
    {
      selector: 'edge[type="writes_data"]',
      style: { 'width': 2.5, 'line-style': 'dashed', 'line-color': '#A855F7', 'target-arrow-color': '#A855F7' },
    },
    {
      selector: 'edge[type="http_request"]',
      style: { 'line-color': '#EF4444', 'target-arrow-color': '#EF4444' },
    },
    {
      selector: 'edge[type="renders_block"], edge[type="enqueues_script"]',
      style: { 'line-style': 'dotted', 'line-color': '#EC4899', 'target-arrow-color': '#EC4899' },
    },
    {
      selector: 'node.dimmed',
      style: { 'opacity': 0.15 },
    },
    {
      selector: 'edge.dimmed',
      style: { 'opacity': 0.05 },
    },
    {
      selector: 'node:selected',
      style: {
        'border-width': 3,
        'border-color': '#FBBF24',
      },
    },
    {
      selector: 'node.highlighted',
      style: { 'border-color': '#FBBF24', 'border-width': 3 },
    },
  ];
}

export function initCytoscape(container, elements, onNodeClick, onNodeHover, onNodeDoubleClick) {
  // Register dagre layout if available
  if (window.cytoscapeDagre) {
    cytoscape.use(window.cytoscapeDagre);
  }

  const cy = cytoscape({
    container,
    elements,
    style: buildStylesheet(),
    layout: LAYOUTS.dagre,
    minZoom: 0.05,
    maxZoom: 4,
    wheelSensitivity: 0.3,
  });

  cy.on('tap', 'node', (evt) => {
    onNodeClick(evt.target.data());
  });

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

  cy.on('dbltap', 'node', (evt) => {
    const node = evt.target;
    const neighborhood = node.closedNeighborhood();
    cy.fit(neighborhood, 60);
    onNodeDoubleClick(node.data());
  });

  cy.on('tap', (evt) => {
    if (evt.target === cy) {
      cy.elements().removeClass('dimmed highlighted');
    }
  });

  return cy;
}
