import { initCytoscape } from './graph.js';
import { applyLayout, pickLayout } from './layouts.js';
import { openSidebar, closeSidebar, initSidebar } from './sidebar.js';
import { initSearch } from './search.js';

// Max nodes to render in Cytoscape. Beyond this the browser hangs.
const RENDER_CAP = 1500;

/**
 * Pick the most-connected nodes to render when the graph exceeds RENDER_CAP.
 * Prioritises nodes with the most edges so the rendered subgraph is meaningful.
 * Returns { nodes, edges, truncated } where truncated = true if capped.
 */
function capElements(allNodes, allEdges) {
  if (allNodes.length <= RENDER_CAP) {
    return { nodes: allNodes, edges: allEdges, truncated: false };
  }

  // Count degree (in + out) for each node
  const degree = {};
  for (const e of allEdges) {
    const s = e.data.source, t = e.data.target;
    degree[s] = (degree[s] || 0) + 1;
    degree[t] = (degree[t] || 0) + 1;
  }

  // Sort nodes by degree descending, take top RENDER_CAP
  const sorted = [...allNodes].sort((a, b) =>
    (degree[b.data.id] || 0) - (degree[a.data.id] || 0)
  );
  const kept    = new Set(sorted.slice(0, RENDER_CAP).map(n => n.data.id));
  const nodes   = allNodes.filter(n => kept.has(n.data.id));
  const edges   = allEdges.filter(e => kept.has(e.data.source) && kept.has(e.data.target));

  return { nodes, edges, truncated: true };
}

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
  const p          = graphData.plugin || {};
  const totalNodes = (graphData.nodes || []).length;
  const totalEdges = (graphData.edges || []).length;
  document.getElementById('plugin-name').textContent    = p.name || 'Unknown Plugin';
  document.getElementById('plugin-version').textContent = p.version ? `v${p.version}` : '';
  document.getElementById('plugin-stats').textContent   = `${totalNodes} nodes · ${totalEdges} edges`;

  // Cap elements for rendering
  const { nodes, edges, truncated } = capElements(graphData.nodes || [], graphData.edges || []);

  if (truncated) {
    showTruncationBanner(totalNodes, nodes.length);
  }

  // Build Cytoscape elements from (possibly capped) set
  const elements = [...nodes, ...edges];

  let currentNodeData = null;

  const cy = initCytoscape(
    document.getElementById('cy'),
    elements,
    (nodeData) => {
      currentNodeData = nodeData;
      openSidebar(nodeData);
    },
    (_nodeData, _pos) => {},
    (_nodeData) => {},
  );

  // Search operates on ALL nodes (not just rendered), so pass full graphData
  initSidebar(cy);
  initSearch(cy, graphData.nodes || []);

  // Auto-select layout based on rendered node count and density
  const density    = nodes.length > 0 ? edges.length / nodes.length : 0;
  const autoLayout = pickLayout(nodes.length, density);

  const layoutSelect = document.getElementById('layout-select');
  if (layoutSelect) {
    layoutSelect.value = autoLayout;
    layoutSelect.addEventListener('change', () => applyLayout(cy, layoutSelect.value));
  }

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

function showTruncationBanner(total, rendered) {
  const banner = document.createElement('div');
  banner.className = 'absolute top-14 left-1/2 -translate-x-1/2 z-10 bg-yellow-900 border border-yellow-600 text-yellow-200 text-xs rounded px-4 py-2 flex items-center gap-3 shadow-lg';
  banner.innerHTML = `
    <span>⚠ Large graph: showing the ${rendered.toLocaleString()} most-connected of ${total.toLocaleString()} nodes. Use filters or search to explore.</span>
    <button class="ml-2 text-yellow-400 hover:text-white font-bold" onclick="this.parentElement.remove()">✕</button>
  `;
  // Insert relative to #main-layout so positioning works
  const layout = document.getElementById('main-layout');
  layout.style.position = 'relative';
  layout.prepend(banner);
}

// Bootstrap after DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', main);
} else {
  main();
}
