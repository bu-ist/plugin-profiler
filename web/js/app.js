import { initCytoscape } from './graph.js';
import { applyLayout, pickLayout } from './layouts.js';
import { openSidebar, closeSidebar, initSidebar, openPluginSummary } from './sidebar.js';
import { initSearch, toggleLibraryFilter } from './search.js';
import { EDGE_VIEW_MODES } from './constants.js';
import { escapeHtml } from './utils.js';

// Max nodes to render in Cytoscape. Beyond this the browser hangs.
const RENDER_CAP = 1500;

// Focus view: seed with the N most-connected nodes, then expand 1 hop up to FOCUS_CAP.
const FOCUS_PRIMARY = 40;

// Hard cap on the focus set after 1-hop expansion.
// Prevents large densely-connected plugins (1000+ nodes) from expanding the
// focus set to hundreds of nodes. 1-hop neighbors are added in degree order
// until this limit is reached. Small plugins that never reach the cap are
// unaffected — they show their full focus neighbourhood.
const FOCUS_CAP = 100;

// ── Module-level state ────────────────────────────────────────────────────────
// Stored at module scope so the focus toggle can swap the rendered set without
// re-fetching data or re-creating the Cytoscape instance.

let _cy         = null;
let _isFocused  = true;   // starts in focus mode
let _allNodes   = [];     // full node list from graph-data.json (after render cap)
let _allEdges   = [];     // full edge list from graph-data.json (after render cap)
let _pluginMeta = {};
let _viewMode   = 'all';  // current edge view mode ('all' | 'structure' | 'behavior')

// ── capElements ───────────────────────────────────────────────────────────────

/**
 * Pick the most-connected nodes to render when the graph exceeds RENDER_CAP.
 *
 * Developer nodes are ALWAYS included regardless of degree — they are what the
 * user came to see. Library nodes (data.is_library === true) fill the remaining
 * slots in degree order. This prevents large bundled libraries (e.g. Ext JS with
 * thousands of highly-connected nodes) from crowding out the developer code.
 *
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

  // Partition: developer nodes are always kept; library nodes fill remaining slots
  const devNodes = allNodes.filter(n => !n.data.is_library);
  const libNodes = allNodes.filter(n =>  n.data.is_library);

  const libSlots  = Math.max(0, RENDER_CAP - devNodes.length);
  const keptLib   = [...libNodes]
    .sort((a, b) => (degree[b.data.id] || 0) - (degree[a.data.id] || 0))
    .slice(0, libSlots);

  const kept  = new Set([...devNodes, ...keptLib].map(n => n.data.id));
  const nodes = allNodes.filter(n => kept.has(n.data.id));
  const edges = allEdges.filter(e => kept.has(e.data.source) && kept.has(e.data.target));

  return { nodes, edges, truncated: true };
}

// ── buildFocusSet ─────────────────────────────────────────────────────────────

/**
 * Build the initial focus set: top FOCUS_PRIMARY nodes by degree plus any nodes
 * in the plugin's main entry file, then expanded by 1 hop.
 *
 * Compound nodes (namespace/dir) are excluded — the focus view is a clean flat
 * graph. They are restored when the user switches to "show all."
 *
 * @param {Array}  allNodes   - Full node list (already render-capped).
 * @param {Array}  allEdges   - Full edge list (already render-capped).
 * @param {Object} pluginMeta - plugin block from graph-data.json.
 * @returns {{ nodes, edges, focusCount, totalCount }}
 */
function buildFocusSet(allNodes, allEdges, pluginMeta) {
  // Count degree for every node
  const degree = {};
  for (const e of allEdges) {
    degree[e.data.source] = (degree[e.data.source] || 0) + 1;
    degree[e.data.target] = (degree[e.data.target] || 0) + 1;
  }

  // Exclude compound group nodes from seeding
  const leafNodes = allNodes.filter(n => n.data.type !== 'namespace' && n.data.type !== 'dir');

  // Seed: main-file nodes + top FOCUS_PRIMARY by degree
  const primary  = new Set();
  const mainFile = pluginMeta?.main_file;
  leafNodes.forEach(n => {
    if (mainFile && n.data.file?.endsWith(mainFile)) primary.add(n.data.id);
  });
  [...leafNodes]
    .sort((a, b) => (degree[b.data.id] || 0) - (degree[a.data.id] || 0))
    .slice(0, FOCUS_PRIMARY)
    .forEach(n => primary.add(n.data.id));

  // Expand 1 hop — capped to FOCUS_CAP so large densely-connected plugins
  // don't balloon the focus set to hundreds of nodes.
  // 1-hop candidates are sorted by degree so the most-connected neighbours
  // are always included first.
  const focusIds = new Set(primary);
  if (focusIds.size < FOCUS_CAP) {
    const oneHop = new Set();
    allEdges.forEach(e => {
      if (primary.has(e.data.source) && !primary.has(e.data.target)) oneHop.add(e.data.target);
      if (primary.has(e.data.target) && !primary.has(e.data.source)) oneHop.add(e.data.source);
    });
    [...oneHop]
      .sort((a, b) => (degree[b] || 0) - (degree[a] || 0))
      .forEach(id => { if (focusIds.size < FOCUS_CAP) focusIds.add(id); });
  }

  // Strip compound parent nodes and the parent reference from child data so the
  // focus view renders as a clean flat graph without compound bounding boxes.
  const nodes = leafNodes
    .filter(n => focusIds.has(n.data.id))
    .map(n => n.data.parent ? { data: { ...n.data, parent: undefined } } : n);
  const edges = allEdges.filter(
    e => focusIds.has(e.data.source) && focusIds.has(e.data.target)
  );

  return { nodes, edges, focusCount: nodes.length, totalCount: leafNodes.length };
}

// ── UI helpers ────────────────────────────────────────────────────────────────

/**
 * Update the focus/show-all toggle button label.
 *
 * @param {number} focusCount - Nodes currently rendered.
 * @param {number} totalCount - Total available leaf nodes.
 */
function updateFocusButton(focusCount, totalCount) {
  const lbl = document.getElementById('focus-label');
  if (!lbl) return;
  lbl.textContent = _isFocused
    ? `⊙ Key nodes (${focusCount} / ${totalCount})`
    : `⊛ All nodes (${totalCount})`;
}

/**
 * Show or update the status banner describing the current view.
 * In focus mode: "Showing N of M nodes — key nodes by connectivity. [Show all →]"
 * Clicking the link switches to show-all mode. Banner is suppressed when focus
 * set equals the full set (small plugin — nothing to trim).
 *
 * @param {number}  focusCount - Nodes currently rendered.
 * @param {number}  totalCount - Total available leaf nodes.
 * @param {boolean} isFocused  - Whether focus mode is active.
 */
function showStatusBanner(focusCount, totalCount, isFocused) {
  // Remove any existing banner
  document.getElementById('status-banner')?.remove();

  // No banner needed when every node is visible
  if (!isFocused || focusCount >= totalCount) return;

  const layout = document.getElementById('main-layout');
  if (!layout) return;
  layout.style.position = 'relative';

  const banner = document.createElement('div');
  banner.id = 'status-banner';
  banner.className = 'absolute top-14 left-1/2 -translate-x-1/2 z-10 bg-slate-800 border border-slate-600 text-slate-300 text-xs rounded px-4 py-2 flex items-center gap-3 shadow-lg';
  banner.innerHTML = `
    <span>⊙ Showing <strong class="text-white">${focusCount}</strong> of ${totalCount} nodes — key nodes by connectivity.</span>
    <button id="show-all-link" class="text-blue-400 hover:text-blue-300 underline whitespace-nowrap">Show all →</button>
    <button id="banner-dismiss" class="ml-1 text-slate-500 hover:text-white font-bold leading-none" title="Dismiss">✕</button>
  `;
  layout.prepend(banner);

  document.getElementById('show-all-link')?.addEventListener('click', () => {
    _isFocused = false;
    switchView();
  });

  document.getElementById('banner-dismiss')?.addEventListener('click', () => {
    banner.remove();
  });
}

// ── switchView ────────────────────────────────────────────────────────────────

/**
 * Swap the Cytoscape element set between focus mode and show-all mode,
 * then re-apply the selected layout.
 */
function switchView() {
  if (!_cy) return;

  _cy.batch(() => {
    _cy.elements().remove();
    if (_isFocused) {
      const f = buildFocusSet(_allNodes, _allEdges, _pluginMeta);
      _cy.add([...f.nodes, ...f.edges]);
      updateFocusButton(f.focusCount, f.totalCount);
      showStatusBanner(f.focusCount, f.totalCount, true);
    } else {
      _cy.add([..._allNodes, ..._allEdges]);
      const leafCount = _allNodes.filter(
        n => n.data.type !== 'namespace' && n.data.type !== 'dir'
      ).length;
      updateFocusButton(leafCount, leafCount);
      showStatusBanner(leafCount, leafCount, false);
    }
  });

  const layoutName = document.getElementById('layout-select')?.value || 'fcose';
  const n          = _cy.nodes().length;
  const maxEdges   = n > 1 ? (n * (n - 1)) / 2 : 1;
  const density    = _cy.edges().length / maxEdges;
  const autoLayout = pickLayout(n, density, _isFocused);

  // In focus mode, update the layout selector to reflect the auto-pick
  const layoutSelect = document.getElementById('layout-select');
  if (layoutSelect && _isFocused) layoutSelect.value = autoLayout;

  applyLayout(_cy, _isFocused ? autoLayout : layoutName);
}

// ── applyViewMode ─────────────────────────────────────────────────────────────

/**
 * Show/hide edges in the rendered graph based on the current _viewMode.
 * Nodes are never hidden — only edges are filtered so the graph topology remains
 * clear even when only one edge category is selected.
 */
function applyViewMode() {
  if (!_cy) return;
  const mode = EDGE_VIEW_MODES[_viewMode];
  _cy.batch(() => {
    if (!mode || !mode.edges) {
      // "all" — restore everything
      _cy.edges().removeClass('view-hidden');
    } else {
      _cy.edges().forEach((edge) => {
        const type = edge.data('type') || '';
        if (mode.edges.has(type)) {
          edge.removeClass('view-hidden');
        } else {
          edge.addClass('view-hidden');
        }
      });
    }
  });
}

// ── main ──────────────────────────────────────────────────────────────────────

function setLoadingStatus(msg) {
  const el = document.getElementById('loading-status');
  if (el) el.textContent = msg;
}

function hideLoading() {
  const overlay = document.getElementById('loading');
  if (!overlay) return;
  overlay.style.opacity = '0';
  overlay.style.pointerEvents = 'none';
  setTimeout(() => overlay.classList.add('hidden'), 300);
}

function showLoadingError(html) {
  const overlay = document.getElementById('loading');
  if (!overlay) return;
  overlay.innerHTML = `<div class="flex flex-col items-center gap-3 max-w-md px-6 text-center">${html}</div>`;
}

async function main() {
  let graphData;

  setLoadingStatus('Fetching analysis data…');
  try {
    const res = await fetch('/data/graph-data.json');
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    setLoadingStatus('Parsing graph data…');
    graphData = await res.json();
  } catch (err) {
    showLoadingError(
      '<p class="text-red-400 font-semibold">Failed to load graph data.</p>' +
      '<p class="text-gray-400 text-sm">Run the analyzer first, then reload this page.</p>' +
      `<p class="text-gray-600 text-xs mt-1">${err.message}</p>`
    );
    return;
  }

  // Handle empty graph
  if (!graphData.nodes || graphData.nodes.length === 0) {
    const p         = graphData.plugin || {};
    const fileCount = p.total_files || 0;
    const hasPhp    = p.php_files   || 0;
    const hasJs     = p.js_files    || 0;

    let hint = '';
    if (fileCount === 0) {
      hint = 'No files were found. Check that the path points to a plugin directory.';
    } else if (hasJs > 0 && hasPhp === 0) {
      hint = `${fileCount} file(s) scanned — all JavaScript, no PHP found. This looks like a standalone JS/React app rather than a WordPress plugin. If this is a block plugin, make sure the PHP entry file is present.`;
    } else if (hasPhp > 0) {
      hint = `${fileCount} file(s) scanned including ${hasPhp} PHP file(s), but no WordPress entities were detected. The plugin may not use standard WordPress hook/class patterns.`;
    } else {
      hint = `${fileCount} file(s) scanned but no WordPress entities were detected.`;
    }

    showLoadingError(`
      <p class="text-yellow-400 text-lg font-semibold">No entities found</p>
      <p class="text-gray-400 text-sm">${hint}</p>
      <p class="text-gray-500 text-xs leading-relaxed">Plugin Profiler analyzes PHP classes, WordPress hooks, REST endpoints, AJAX handlers, data sources, Gutenberg blocks, and file dependencies. React/JS build artifacts are also scanned for <code class="text-gray-400">registerBlockType</code> and <code class="text-gray-400">wp.hooks</code> calls.</p>
      ${p.name ? `<p class="text-gray-600 text-xs mt-2">Analyzed: <span class="text-gray-500">${escapeHtml(p.name)}</span></p>` : ''}
    `);
    return;
  }

  // Populate plugin meta
  const p          = graphData.plugin || {};
  const totalNodes = (graphData.nodes || []).length;
  const totalEdges = (graphData.edges || []).length;
  document.getElementById('plugin-name').textContent    = p.name || 'Unknown Plugin';
  document.getElementById('plugin-version').textContent = p.version ? `v${p.version}` : '';
  document.getElementById('plugin-stats').textContent   = `${totalNodes} nodes · ${totalEdges} edges`;

  // Cap elements for rendering
  const { nodes, edges } = capElements(graphData.nodes || [], graphData.edges || []);

  // Store module-level state for the focus toggle
  _allNodes   = nodes;
  _allEdges   = edges;
  _pluginMeta = p;
  _isFocused  = true;

  // Build initial focus set — flat, no compound nodes
  setLoadingStatus('Building focus view…');
  const focused = buildFocusSet(_allNodes, _allEdges, _pluginMeta);

  setLoadingStatus('Initialising graph…');
  // Initialise Cytoscape with just the focus set
  _cy = initCytoscape(
    document.getElementById('cy'),
    [...focused.nodes, ...focused.edges],
    (nodeData) => { openSidebar(nodeData); },
    (_nodeData, _pos) => {},
    (_nodeData) => {},
  );

  // Initialize expand-collapse extension. animate:false prevents the compound-node
  // explosion that happens when physics runs frame-by-frame with nested elements.
  _cy.expandCollapse({
    layoutBy: {
      name:              'fcose',
      animate:           false,
      animationDuration: 0,
      quality:           'draft',
      randomize:         true,
    },
    undoable:          false,
    fisheye:           false,
    animate:           false,
    animationDuration: 0,
  });

  // Search operates on ALL nodes (not just rendered), so pass full graphData
  initSidebar(_cy);
  initSearch(_cy, graphData.nodes || []);

  // Show AI summary as the default sidebar state when available
  openPluginSummary(p);

  // Auto-select layout for the focus set (small, sparse → always fCoSE)
  const maxEdges   = focused.nodes.length > 1
    ? (focused.nodes.length * (focused.nodes.length - 1)) / 2
    : 1;
  const density    = focused.edges.length / maxEdges;
  const autoLayout = pickLayout(focused.nodes.length, density, true);

  const layoutSelect = document.getElementById('layout-select');
  if (layoutSelect) {
    layoutSelect.value = autoLayout;
    layoutSelect.addEventListener('change', () => {
      // User is manually choosing a layout — stay in current view mode
      applyLayout(_cy, layoutSelect.value);
    });
  }

  setLoadingStatus('Applying layout…');
  applyLayout(_cy, autoLayout);

  // Graph is ready — fade out the loading overlay
  hideLoading();

  updateFocusButton(focused.focusCount, focused.totalCount);
  showStatusBanner(focused.focusCount, focused.totalCount, true);

  // Show the Dev-only filter button only when the graph contains library nodes
  const hasLibraryNodes = (graphData.nodes || []).some(n => n.data?.is_library === true);
  const libBtn = document.getElementById('lib-filter-btn');
  if (libBtn && hasLibraryNodes) libBtn.classList.remove('hidden');

  // Focus/Show-all toggle button
  document.getElementById('focus-btn')?.addEventListener('click', () => {
    _isFocused = !_isFocused;
    switchView();
  });

  // View mode buttons — filter edges to Structure or Behavior subset
  document.getElementById('view-mode-btns')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-view]');
    if (!btn) return;
    const mode = btn.dataset.view;
    if (mode === _viewMode) return;
    _viewMode = mode;

    // Update button highlight
    document.querySelectorAll('.view-mode-btn').forEach((b) => {
      const active = b.dataset.view === mode;
      b.classList.toggle('bg-blue-600', active);
      b.classList.toggle('text-white',  active);
      b.classList.toggle('bg-gray-700', !active);
      b.classList.toggle('text-gray-400', !active);
    });

    applyViewMode();
  });

  // Collapse/Expand toggle — requires the expand-collapse extension to be
  // initialised. The extension is registered in graph.js; the API is available
  // via _cy.expandCollapse('get') after the first call to _cy.expandCollapse({}).
  document.getElementById('collapse-btn')?.addEventListener('click', () => {
    const api        = _cy.expandCollapse('get');
    const groupNodes = _cy.nodes('[type = "namespace"], [type = "dir"]');
    if (!api || !groupNodes.length) return;

    // Use type-based selector rather than :parent — collapsed groups have no
    // visible children so :parent would not match them, making the toggle
    // unable to detect the collapsed state.
    const anyCollapsed = groupNodes.some((n) => api.isCollapsed(n));
    if (anyCollapsed) {
      api.expandAll();
      document.getElementById('collapse-btn').textContent = '⊟ Groups';
    } else {
      api.collapseAll();
      document.getElementById('collapse-btn').textContent = '⊞ Groups';
    }
  });

  // Dev-only / library filter button
  document.getElementById('lib-filter-btn')?.addEventListener('click', () => {
    const hiding = toggleLibraryFilter();
    const btn = document.getElementById('lib-filter-btn');
    const lbl = document.getElementById('lib-filter-label');
    if (lbl) lbl.textContent = hiding ? '⚙ Dev only (active)' : '⚙ Dev only';
    if (btn) btn.classList.toggle('bg-blue-700', hiding);
    if (btn) btn.classList.toggle('bg-gray-700', !hiding);
  });

  // Zoom controls — zoom toward the center of the viewport
  const zoomCenter = () => {
    const ext = _cy.extent();
    return {
      x: (ext.x1 + ext.x2) / 2,
      y: (ext.y1 + ext.y2) / 2,
    };
  };
  document.getElementById('zoom-in')?.addEventListener('click',  () => _cy.zoom({ level: _cy.zoom() * 1.3,  position: zoomCenter() }));
  document.getElementById('zoom-out')?.addEventListener('click', () => _cy.zoom({ level: _cy.zoom() * 0.77, position: zoomCenter() }));
  document.getElementById('zoom-fit')?.addEventListener('click', () => _cy.fit());

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
