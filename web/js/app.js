import { initCytoscape } from './graph.js';
import { applyLayout, pickLayout } from './layouts.js';
import { openSidebar, closeSidebar, initSidebar, openPluginSummary } from './sidebar.js';
import { initSearch, toggleLibraryFilter, isLibraryFilterActive } from './search.js';
import { EDGE_VIEW_MODES, EDGE_TYPE_META } from './constants.js';
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
function buildFocusSet(allNodes, allEdges, pluginMeta, hideLibrary = false) {
  // Count degree for every node
  const degree = {};
  for (const e of allEdges) {
    degree[e.data.source] = (degree[e.data.source] || 0) + 1;
    degree[e.data.target] = (degree[e.data.target] || 0) + 1;
  }

  // Exclude compound group nodes and (optionally) library nodes from seeding
  const leafNodes = allNodes.filter(n =>
    n.data.type !== 'namespace' &&
    n.data.type !== 'dir' &&
    (!hideLibrary || !n.data.is_library)
  );

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

  // Use fixed positioning so the banner floats above the Cytoscape canvas
  // without being clipped or intercepted by canvas pointer events.
  // Bottom-center placement keeps it out of the graph workspace.
  const banner = document.createElement('div');
  banner.id = 'status-banner';
  banner.setAttribute('role', 'status');
  banner.setAttribute('aria-live', 'polite');
  banner.setAttribute('aria-atomic', 'true');
  banner.className = 'fixed bottom-10 left-1/2 -translate-x-1/2 z-[200] bg-slate-800 border border-slate-600 text-slate-300 text-xs rounded px-4 py-2 flex items-center gap-3 shadow-lg whitespace-nowrap';
  banner.innerHTML = `
    <span>⊙ Showing <strong class="text-white">${focusCount}</strong> of ${totalCount} nodes — key nodes by connectivity.</span>
    <button data-banner-action="show-all" class="text-blue-400 hover:text-blue-300 underline" title="Switch to full graph view">Show all →</button>
    <button data-banner-action="dismiss" class="ml-1 text-slate-400 hover:text-white text-sm leading-none" title="Dismiss">✕</button>
  `;
  document.body.appendChild(banner);

  // Use event delegation on the banner container — same pattern as sidebar-close.
  // Querying individual child IDs after appendChild can silently fail when the
  // banner is re-created quickly (e.g. focus toggle), so delegation on the
  // parent element is more reliable.
  banner.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-banner-action]');
    if (!btn) return;
    e.stopPropagation();
    if (btn.dataset.bannerAction === 'dismiss') {
      banner.remove();
    } else if (btn.dataset.bannerAction === 'show-all') {
      _isFocused = false;
      switchView();
    }
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
      const f = buildFocusSet(_allNodes, _allEdges, _pluginMeta, isLibraryFilterActive());
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

  // Re-apply view mode filter (switchView rebuilds elements without classes)
  applyViewMode();
  updateViewModeCounts();
  renderLegend();
}

// ── applyViewMode ─────────────────────────────────────────────────────────────

/**
 * Show/hide edges in the rendered graph based on the current _viewMode.
 * When a filtered mode is active, nodes with no visible edges are ghost-dimmed
 * (opacity 0.12, labels hidden) so the graph topology stays readable without
 * unrelated nodes creating visual noise. Compound nodes are never dimmed.
 */
function applyViewMode() {
  if (!_cy) return;
  const mode = EDGE_VIEW_MODES[_viewMode];
  _cy.batch(() => {
    if (!mode || !mode.edges) {
      // "all" — restore everything
      _cy.edges().removeClass('view-hidden');
      _cy.nodes().removeClass('view-dimmed');
    } else {
      _cy.edges().forEach((edge) => {
        const type = edge.data('type') || '';
        if (mode.edges.has(type)) {
          edge.removeClass('view-hidden');
        } else {
          edge.addClass('view-hidden');
        }
      });
      // Ghost-dim nodes that have no visible edges in this mode.
      // Compound group nodes (namespace/dir) are excluded — they shouldn't
      // fade out just because their children lack edges in a given mode.
      _cy.nodes().forEach((node) => {
        const type = node.data('type') || '';
        if (type === 'namespace' || type === 'dir') {
          node.removeClass('view-dimmed');
          return;
        }
        const hasVisible = node.connectedEdges().some(e => !e.hasClass('view-hidden'));
        node.toggleClass('view-dimmed', !hasVisible);
      });
    }
  });
}

/**
 * Update Requirements / Data flow button labels with the count of edges
 * of that type present in the currently-rendered graph.
 * Called after graph init and after switchView() changes the element set.
 */
function updateViewModeCounts() {
  if (!_cy) return;
  document.querySelectorAll('.view-mode-btn[data-view]').forEach((btn) => {
    const view = btn.dataset.view;
    if (view === 'all') return;
    const modeDef = EDGE_VIEW_MODES[view];
    if (!modeDef?.edges) return;
    const count = _cy.edges().filter(e => modeDef.edges.has(e.data('type') || '')).length;
    const base  = view === 'requirements' ? 'Requirements' : 'Data flow';
    btn.textContent = `${base} (${count})`;
  });
}

// ── Legend panel ─────────────────────────────────────────────────────────────

/**
 * Build an inline SVG swatch for the legend: a short coloured line with the
 * correct dash pattern and a filled arrow-shape marker at the right end.
 */
function legendSwatch(color, lineStyle, arrowShape) {
  const dash = lineStyle === 'dashed' ? 'stroke-dasharray="4 3"' : lineStyle === 'dotted' ? 'stroke-dasharray="1.5 2.5"' : '';
  // Simple marker shapes — just the right visual glyph, not a full Cytoscape replica
  const markers = {
    triangle:           `<polygon points="14,6 20,10 14,14" fill="${color}"/>`,
    vee:                `<polyline points="14,6 20,10 14,14" fill="none" stroke="${color}" stroke-width="2"/>`,
    diamond:            `<polygon points="17,5 21,10 17,15 13,10" fill="${color}"/>`,
    square:             `<rect x="14" y="6" width="7" height="8" fill="${color}"/>`,
    circle:             `<circle cx="17" cy="10" r="4" fill="${color}"/>`,
    tee:                `<line x1="18" y1="5" x2="18" y2="15" stroke="${color}" stroke-width="2.5"/>`,
    chevron:            `<polyline points="14,6 20,10 14,14" fill="none" stroke="${color}" stroke-width="2"/>`,
    'triangle-backcurve': `<polygon points="13,6 20,10 13,14" fill="${color}"/><line x1="15" y1="8" x2="15" y2="12" stroke="#1e293b" stroke-width="1"/>`,
  };
  const marker = markers[arrowShape] || markers.triangle;
  return `<svg width="28" height="20" viewBox="0 0 28 20" class="shrink-0"><line x1="2" y1="10" x2="18" y2="10" stroke="${color}" stroke-width="2" ${dash}/>${marker}</svg>`;
}

/**
 * Render the edge legend panel grouped by family, showing only edge types
 * present in the current graph with their counts.
 */
function renderLegend() {
  const container = document.getElementById('legend-content');
  if (!container || !_cy) return;

  // Count only visible edges (exclude view-hidden edges filtered by the active mode)
  const counts = {};
  _cy.edges().filter(e => !e.hasClass('view-hidden')).forEach(e => {
    const t = e.data('type') || '';
    counts[t] = (counts[t] || 0) + 1;
  });

  // Group EDGE_TYPE_META entries by family, keeping only types with count > 0
  const families = {};
  for (const [type, meta] of Object.entries(EDGE_TYPE_META)) {
    if (!counts[type]) continue;
    if (!families[meta.family]) families[meta.family] = [];
    families[meta.family].push({ type, ...meta, count: counts[type] });
  }

  if (Object.keys(families).length === 0) {
    container.innerHTML = '<p class="text-slate-500 text-xs">No edges in current view.</p>';
    return;
  }

  let html = '';
  for (const [family, entries] of Object.entries(families)) {
    html += `<div class="mb-2"><div class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider mb-1">${family}</div>`;
    for (const e of entries) {
      const label = e.type.replace(/_/g, ' ');
      html += `<div class="flex items-center gap-1.5 py-0.5">`;
      html += legendSwatch(e.color, e.lineStyle, e.arrowShape);
      html += `<span class="text-xs text-slate-300 truncate">${label}</span>`;
      html += `<span class="ml-auto text-[10px] text-slate-500">${e.count}</span>`;
      html += `</div>`;
    }
    html += `</div>`;
  }
  container.innerHTML = html;
}

// ── ARIA live announcements ─────────────────────────────────────────────────

/** Push a message to the sr-only live region so screen readers announce it. */
function announce(msg) {
  const el = document.getElementById('filter-announce');
  if (!el) return;
  el.textContent = msg;
  // Reset after a short delay so the same message can be re-announced
  setTimeout(() => { el.textContent = ''; }, 1500);
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

  // Show host path + re-analyze button
  const hostPath = p.host_path;
  const pathEl   = document.getElementById('plugin-path');
  if (pathEl && hostPath) {
    pathEl.textContent = hostPath;
    pathEl.title       = hostPath;
    pathEl.classList.remove('hidden');
    document.getElementById('reanalyze-btn')?.classList.remove('hidden');
  }

  // Re-analyze panel
  const CONTROLLER_URL = 'http://localhost:9001';

  function openReanalyzePanel() {
    const panel = document.getElementById('reanalyze-panel');
    const input = document.getElementById('reanalyze-path');
    if (input) input.value = hostPath || '';
    document.getElementById('reanalyze-status')?.classList.add('hidden');
    panel?.classList.remove('hidden');
    document.getElementById('reanalyze-btn')?.setAttribute('aria-expanded', 'true');
    // Focus the path input once panel is visible
    setTimeout(() => input?.focus(), 50);
  }

  function closeReanalyzePanel() {
    document.getElementById('reanalyze-panel')?.classList.add('hidden');
    document.getElementById('reanalyze-btn')?.setAttribute('aria-expanded', 'false');
    document.getElementById('reanalyze-btn')?.focus();
  }

  document.getElementById('reanalyze-btn')?.addEventListener('click', () => {
    const panel = document.getElementById('reanalyze-panel');
    if (panel?.classList.contains('hidden')) {
      openReanalyzePanel();
    } else {
      closeReanalyzePanel();
    }
  });

  // Focus trap + Escape key for the dialog
  document.getElementById('reanalyze-panel')?.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      e.stopPropagation();
      closeReanalyzePanel();
      return;
    }
    if (e.key !== 'Tab') return;
    const panel    = document.getElementById('reanalyze-panel');
    const focusable = [...panel.querySelectorAll('button:not([disabled]), input:not([disabled])')];
    const first    = focusable[0];
    const last     = focusable[focusable.length - 1];
    if (e.shiftKey) {
      if (document.activeElement === first) { e.preventDefault(); last.focus(); }
    } else {
      if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
    }
  });

  document.getElementById('reanalyze-cancel')?.addEventListener('click', () => {
    closeReanalyzePanel();
  });
  document.getElementById('reanalyze-submit')?.addEventListener('click', async () => {
    const path      = document.getElementById('reanalyze-path')?.value.trim();
    if (!path) return;
    const statusEl  = document.getElementById('reanalyze-status');
    const submitBtn = document.getElementById('reanalyze-submit');
    statusEl?.classList.remove('hidden');
    if (statusEl) statusEl.textContent = 'Analyzing…';
    if (submitBtn) submitBtn.disabled  = true;
    try {
      await fetch(`${CONTROLLER_URL}/analyze`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ path, noDescriptions: true }),
      });
      const startedAt = new Date().toISOString();
      const poll = setInterval(async () => {
        try {
          const r = await fetch(`${CONTROLLER_URL}/status`);
          const s = await r.json();
          if (!s.running && s.analyzedAt && s.analyzedAt > startedAt) {
            clearInterval(poll);
            if (statusEl) statusEl.textContent = 'Done — reloading…';
            setTimeout(() => location.reload(), 800);
          }
        } catch { /* controller not ready yet, keep polling */ }
      }, 2000);
    } catch {
      if (statusEl) statusEl.textContent = 'Controller not reachable — is it running?';
      if (submitBtn) submitBtn.disabled  = false;
    }
  });

  // Cap elements for rendering
  const { nodes, edges } = capElements(graphData.nodes || [], graphData.edges || []);

  // Store module-level state for the focus toggle
  _allNodes   = nodes;
  _allEdges   = edges;
  _pluginMeta = p;
  _isFocused  = true;

  // Build initial focus set — flat, no compound nodes
  setLoadingStatus('Building focus view…');
  const focused = buildFocusSet(_allNodes, _allEdges, _pluginMeta, isLibraryFilterActive());

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

  // Populate Requirements / Data flow counts now that _cy is ready
  updateViewModeCounts();
  renderLegend();

  // Legend toggle button
  document.getElementById('legend-btn')?.addEventListener('click', () => {
    const panel = document.getElementById('legend-panel');
    const btn   = document.getElementById('legend-btn');
    const open  = panel?.classList.toggle('hidden') === false;
    btn?.setAttribute('aria-expanded', String(open));
  });

  // Show the Dev-only filter button only when the graph contains library nodes
  const allLibraryNodes = (graphData.nodes || []).filter(n => n.data?.is_library === true);
  const libBtn = document.getElementById('lib-filter-btn');
  if (libBtn && allLibraryNodes.length) libBtn.classList.remove('hidden');

  // Focus/Show-all toggle button
  document.getElementById('focus-btn')?.addEventListener('click', () => {
    _isFocused = !_isFocused;
    document.getElementById('focus-btn')?.setAttribute('aria-pressed', String(_isFocused));
    switchView();
  });

  // View mode buttons — filter edges to Structure or Behavior subset
  document.getElementById('view-mode-btns')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-view]');
    if (!btn) return;
    const mode = btn.dataset.view;
    if (mode === _viewMode) return;
    _viewMode = mode;

    // Update button highlight — each mode has its own active/inactive colour pair
    // so "Requirements" stays blue and "Data flow" stays orange at a glance.
    const BTN_COLORS = {
      all:          { active: ['bg-blue-600',   'text-white'],        inactive: ['bg-gray-700', 'text-gray-300'] },
      requirements: { active: ['bg-blue-700',   'text-white'],        inactive: ['bg-gray-700', 'text-blue-300'] },
      data:         { active: ['bg-orange-700', 'text-white'],        inactive: ['bg-gray-700', 'text-orange-300'] },
    };
    document.querySelectorAll('.view-mode-btn').forEach((b) => {
      const isActive = b.dataset.view === mode;
      const colors   = BTN_COLORS[b.dataset.view] ?? BTN_COLORS.all;
      b.classList.remove(...colors.active, ...colors.inactive);
      b.classList.add(...(isActive ? colors.active : colors.inactive));
      b.setAttribute('aria-pressed', String(isActive));
    });

    applyViewMode();
    renderLegend();

    // Announce the mode change for screen readers
    const visibleCount = _cy.edges().filter(e => !e.hasClass('view-hidden')).length;
    const modeLabel = mode === 'all' ? 'All' : mode === 'requirements' ? 'Requirements' : 'Data flow';
    announce(`Showing ${modeLabel} edges: ${visibleCount} visible`);
  });

  // Arrow-key navigation within the view mode toolbar (roving tabindex)
  document.getElementById('view-mode-btns')?.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    const btns = [...document.querySelectorAll('.view-mode-btn')];
    const idx  = btns.indexOf(document.activeElement);
    if (idx < 0) return;
    e.preventDefault();
    const next = e.key === 'ArrowRight'
      ? btns[(idx + 1) % btns.length]
      : btns[(idx - 1 + btns.length) % btns.length];
    next.focus();
  });

  // Collapse/Expand toggle — requires the expand-collapse extension to be
  // initialised. The extension is registered in graph.js; the API is available
  // via _cy.expandCollapse('get') after the first call to _cy.expandCollapse({}).
  document.getElementById('collapse-btn')?.addEventListener('click', () => {
    // Compound nodes only exist in show-all mode — auto-switch if needed
    if (_isFocused) {
      _isFocused = false;
      switchView();
    }
    const api        = _cy.expandCollapse('get');
    const groupNodes = _cy.nodes('[type = "namespace"], [type = "dir"]');
    if (!api || !groupNodes.length) return;

    // Use type-based selector rather than :parent — collapsed groups have no
    // visible children so :parent would not match them, making the toggle
    // unable to detect the collapsed state.
    const anyCollapsed = groupNodes.some((n) => api.isCollapsed(n));
    const btn = document.getElementById('collapse-btn');
    if (anyCollapsed) {
      api.expandAll();
      if (btn) { btn.textContent = '⊟ Groups'; btn.setAttribute('aria-pressed', 'false'); }
    } else {
      api.collapseAll();
      if (btn) { btn.textContent = '⊞ Groups'; btn.setAttribute('aria-pressed', 'true'); }
    }
  });

  // Dev-only / library filter button
  document.getElementById('lib-filter-btn')?.addEventListener('click', () => {
    const hiding = toggleLibraryFilter();
    const btn = document.getElementById('lib-filter-btn');
    const lbl = document.getElementById('lib-filter-label');
    if (lbl) {
      if (hiding) {
        const libCount = allLibraryNodes.length;
        lbl.textContent = `⚙ Dev only (${libCount} hidden)`;
      } else {
        lbl.textContent = '⚙ Dev only';
      }
    }
    if (btn) btn.classList.toggle('bg-blue-700', hiding);
    if (btn) btn.classList.toggle('bg-gray-700', !hiding);
    if (btn) btn.setAttribute('aria-pressed', String(hiding));
    announce(hiding ? `Dev only: ${allLibraryNodes.length} library nodes hidden` : 'Dev only off: showing all nodes');
    // In focus mode, rebuild the focus set with/without library nodes
    if (_isFocused) switchView();
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
    // Copy file path to clipboard when file-path button is clicked.
    const copyBtn = e.target.closest('[data-copy-path]');
    if (copyBtn) {
      const path = copyBtn.dataset.copyPath;
      navigator.clipboard.writeText(path).then(() => {
        const savedHtml = copyBtn.innerHTML;
        copyBtn.textContent = '✓ Copied!';
        copyBtn.classList.replace('text-blue-400', 'text-green-400');
        setTimeout(() => {
          copyBtn.innerHTML = savedHtml;
          copyBtn.classList.replace('text-green-400', 'text-blue-400');
        }, 1500);
      });
      return;
    }
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
