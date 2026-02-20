import { initCytoscape } from './graph.js';
import { applyLayout, LAYOUTS } from './layouts.js';
import { openSidebar, closeSidebar, initSidebar } from './sidebar.js';
import { initSearch } from './search.js';

async function main() {
  let graphData;

  try {
    const res = await fetch('/data/graph-data.json');
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    graphData = await res.json();
  } catch (err) {
    document.getElementById('loading').innerHTML =
      '<p class="text-red-400">Failed to load graph data. Run the analyzer first.</p>' +
      `<p class="text-gray-500 text-xs mt-1">${err.message}</p>`;
    return;
  }

  document.getElementById('loading').classList.add('hidden');
  document.getElementById('main-layout').classList.remove('hidden');

  // Populate plugin meta
  const p = graphData.plugin || {};
  document.getElementById('plugin-name').textContent    = p.name || 'Unknown Plugin';
  document.getElementById('plugin-version').textContent = p.version ? `v${p.version}` : '';
  document.getElementById('plugin-stats').textContent   =
    `${(graphData.nodes || []).length} nodes · ${(graphData.edges || []).length} edges`;

  // Build Cytoscape elements
  const elements = [
    ...(graphData.nodes || []),
    ...(graphData.edges || []),
  ];

  let currentNodeData = null;

  const cy = initCytoscape(
    document.getElementById('cy'),
    elements,
    (nodeData) => {
      currentNodeData = nodeData;
      openSidebar(nodeData);
    },
    (_nodeData, _pos) => {
      // hover tooltip — handled by CSS/title attr; no custom implementation needed
    },
    (_nodeData) => {
      // double-click: already zooms in graph.js
    },
  );

  initSidebar(cy);
  initSearch(cy);

  // Auto-select layout: dagre works well for small connected graphs;
  // CoSE handles large or sparse graphs without collapsing into a band.
  const nodeCount = (graphData.nodes || []).length;
  const edgeCount = (graphData.edges || []).length;
  const density   = nodeCount > 0 ? edgeCount / nodeCount : 0;
  const autoLayout = (nodeCount > 200 || density < 0.6) ? 'cose' : 'dagre';

  const layoutSelect = document.getElementById('layout-select');
  if (layoutSelect) {
    layoutSelect.value = autoLayout;
    layoutSelect.addEventListener('change', () => applyLayout(cy, layoutSelect.value));
  }

  // Apply the chosen layout (replaces the dagre default set in initCytoscape)
  applyLayout(cy, autoLayout);

  // Zoom controls — zoom toward the center of the viewport
  const zoomCenter = () => {
    const ext = cy.extent();
    return {
      x: (ext.x1 + ext.x2) / 2,
      y: (ext.y1 + ext.y2) / 2,
    };
  };
  document.getElementById('zoom-in')?.addEventListener('click',  () => cy.zoom({ level: cy.zoom() * 1.3, position: zoomCenter() }));
  document.getElementById('zoom-out')?.addEventListener('click', () => cy.zoom({ level: cy.zoom() * 0.77, position: zoomCenter() }));
  document.getElementById('zoom-fit')?.addEventListener('click', () => cy.fit());

  // Sidebar close button (delegated, since sidebar content is re-rendered)
  document.getElementById('sidebar')?.addEventListener('click', (e) => {
    if (e.target.id === 'sidebar-close' || e.target.closest('#sidebar-close')) {
      closeSidebar();
    }
  });
}

// Bootstrap after DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', main);
} else {
  main();
}
