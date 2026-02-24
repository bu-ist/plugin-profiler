/**
 * Search and type-filter controls for the Plugin Profiler graph.
 *
 * Public entry point: initSearch(cy, allNodes, { onExpandRequest, onRestoreFocus }).
 * Wires the text search input, builds type-filter toggle buttons from the
 * live node set, and binds keyboard shortcuts (/, F, Esc, 1–9).
 *
 * Search queries the FULL node list (_allNodes) — not just the rendered
 * Cytoscape subset.  When matches exist only outside the focus set, the
 * graph auto-expands to show-all via the onExpandRequest callback.  When
 * the query is cleared, focus mode is restored via onRestoreFocus.
 */

import { nodeBadge } from './constants.js';

let _cy = null;
let _activeTypes = new Set();
let _allNodes = null;           // full node list (may exceed rendered set)
let _hideLibrary = false;
let _fitTimer = null;           // debounce timer for search-triggered fit
let _savedViewport = null;      // viewport before search began
let _expandedBySearch = false;  // true when search triggered show-all
let _expandTimer = null;        // debounce timer for auto-expansion
let _onExpandRequest = null;    // callback: switch to show-all
let _onRestoreFocus = null;     // callback: switch back to focus

export function initSearch(cy, allNodes = null, { onExpandRequest, onRestoreFocus } = {}) {
  _cy = cy;
  _allNodes = allNodes;
  _onExpandRequest = onExpandRequest || null;
  _onRestoreFocus = onRestoreFocus || null;

  // Build type set from the FULL node list (not just the focus subset),
  // so all types get toggle buttons even if some are outside the initial view.
  // Exclude compound grouping types (namespace, dir) — they're structural, not
  // entity types that users want to filter.
  const STRUCTURAL_TYPES = new Set(['namespace', 'dir']);
  const allTypes = new Set(
    (allNodes && allNodes.length
      ? allNodes.map((n) => (n.data ? n.data.type : n.type)).filter(Boolean)
      : cy.nodes().map((n) => n.data('type'))
    ).filter((t) => !STRUCTURAL_TYPES.has(t))
  );
  _activeTypes = new Set(allTypes);

  buildTypeFilters(allTypes);
  populateAutocomplete();
  bindSearchInput();
  bindKeyboardShortcuts();
}

/**
 * Reset the search-triggered expansion flag.
 * Call this when the user manually toggles focus/show-all so that
 * clearing the search afterwards does not re-toggle the view.
 */
export function resetSearchExpansion() {
  _expandedBySearch = false;
  clearTimeout(_expandTimer);
}

/**
 * Toggle the library-code filter.
 * When enabled, nodes with data.is_library === true are hidden.
 * Returns the new state (true = hiding library nodes).
 */
export function toggleLibraryFilter() {
  _hideLibrary = !_hideLibrary;
  applyFilters();
  return _hideLibrary;
}

export function isLibraryFilterActive() { return _hideLibrary; }

function bindSearchInput() {
  const input = document.getElementById('search-input');
  if (!input) return;

  input.addEventListener('input', () => applyFilters());
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      input.value = '';
      input.blur();
      applyFilters(); // restores viewport via _savedViewport
    }
  });
}

function buildTypeFilters(allTypes) {
  const container = document.getElementById('type-filters');
  if (!container) return;

  container.innerHTML = '';

  [...allTypes].sort().forEach((type, i) => {
    const btn = document.createElement('button');
    const colorClass = nodeBadge(type);
    btn.className = `type-filter-btn px-2 py-0.5 rounded text-xs font-medium ${colorClass} opacity-100 transition-opacity focus:outline-none focus:ring-2 focus:ring-white`;
    btn.textContent = type.replace(/_/g, ' ');
    btn.dataset.type = type;
    btn.title = `Toggle ${type} nodes (${i + 1})`;
    btn.setAttribute('aria-pressed', 'true');

    btn.addEventListener('click', () => toggleType(type, btn));
    container.appendChild(btn);
  });
  // Show the filter row now that it has buttons
  container.classList.remove('hidden');
  container.classList.add('flex');
}

function toggleType(type, btn) {
  if (_activeTypes.has(type)) {
    _activeTypes.delete(type);
    btn.classList.add('opacity-30');
    btn.setAttribute('aria-pressed', 'false');
  } else {
    _activeTypes.add(type);
    btn.classList.remove('opacity-30');
    btn.setAttribute('aria-pressed', 'true');
  }
  applyFilters();
}

// ── Core search + filter logic ───────────────────────────────────────────────

/**
 * Test whether a raw JSON node (from _allNodes) passes the current filters.
 */
function nodeMatchesQuery(n, query) {
  const d     = n.data || n;
  const type  = d.type || '';
  const label = (d.label || d.id || '').toLowerCase();
  const isLib = d.is_library || false;

  const typeVisible    = _activeTypes.has(type);
  const searchMatch    = !query || label.includes(query);
  const libraryVisible = !_hideLibrary || !isLib;

  return typeVisible && searchMatch && libraryVisible;
}

/**
 * Count how many nodes in the FULL _allNodes list match the current query
 * and active filters (type toggles + library filter).
 */
function countFullMatches(query) {
  if (!query || !_allNodes) return 0;
  let count = 0;
  for (const n of _allNodes) {
    if (nodeMatchesQuery(n, query)) count++;
  }
  return count;
}

export function applyFilters() {
  if (!_cy) return;

  // Cancel any pending auto-expansion from a previous keystroke
  clearTimeout(_expandTimer);

  const query = (document.getElementById('search-input')?.value || '').toLowerCase().trim();

  // ── Count matches against FULL node list ────────────────────────────────
  const fullMatchCount = countFullMatches(query);

  // ── Filter rendered Cytoscape nodes ─────────────────────────────────────
  let renderedMatchCount = 0;
  _cy.batch(() => {
    _cy.nodes().forEach((node) => {
      const type = node.data('type');
      const label = (node.data('label') || '').toLowerCase();
      const typeVisible = _activeTypes.has(type);
      const searchMatch = !query || label.includes(query);
      const libraryVisible = !_hideLibrary || !node.data('is_library');

      if (typeVisible && searchMatch && libraryVisible) {
        node.style('display', 'element');
        node.removeClass('dimmed');
        if (query) renderedMatchCount++;
      } else {
        node.style('display', 'none');
      }
    });

    // Hide edges where either endpoint is hidden
    _cy.edges().forEach((edge) => {
      const src = _cy.getElementById(edge.data('source'));
      const tgt = _cy.getElementById(edge.data('target'));
      const visible = src.style('display') !== 'none' && tgt.style('display') !== 'none';
      edge.style('display', visible ? 'element' : 'none');
    });
  });

  // ── Auto-expand: matches exist outside focus set ────────────────────────
  // Debounce the expansion to avoid jarring per-keystroke view switches.
  if (query && fullMatchCount > 0 && renderedMatchCount === 0
      && !_expandedBySearch && _onExpandRequest) {
    _expandTimer = setTimeout(() => {
      _expandedBySearch = true;
      _onExpandRequest();
      // Re-apply filters on the now-expanded Cytoscape element set
      applyFilters();
    }, 400);
    // Show the total count immediately (yellow = "matches exist, expanding…")
    updateMatchCount(query, 0, fullMatchCount);
    return; // wait for the debounced expansion
  }

  // ── Auto-fit to visible search results (debounced) ──────────────────────
  if (query && renderedMatchCount > 0) {
    if (!_savedViewport) {
      _savedViewport = { zoom: _cy.zoom(), pan: { ..._cy.pan() } };
    }
    clearTimeout(_fitTimer);
    _fitTimer = setTimeout(() => {
      const visible = _cy.nodes().filter(n => n.style('display') !== 'none');
      if (visible.length > 0) {
        _cy.fit(visible, 60);
      }
    }, 300);
  } else if (!query && _savedViewport) {
    // Restore viewport when search is cleared
    clearTimeout(_fitTimer);
    _cy.viewport({ zoom: _savedViewport.zoom, pan: _savedViewport.pan });
    _savedViewport = null;
  }

  // ── Restore focus when search is cleared after auto-expansion ───────────
  if (!query && _expandedBySearch) {
    _expandedBySearch = false;
    if (_onRestoreFocus) _onRestoreFocus();
  }

  // ── Update match count badge ────────────────────────────────────────────
  updateMatchCount(query, renderedMatchCount, fullMatchCount);
}

function populateAutocomplete() {
  const datalist = document.getElementById('node-labels');
  if (!datalist) return;

  datalist.innerHTML = '';

  // Use full node list if available (covers truncated graphs), else fall back to cy
  const source = _allNodes
    ? _allNodes.map(n => n.data.label).filter(Boolean)
    : _cy.nodes().map(n => n.data('label')).filter(Boolean);

  const seen = new Set();
  source.forEach((label) => {
    if (seen.has(label)) return;
    seen.add(label);
    const opt = document.createElement('option');
    opt.value = label;
    datalist.appendChild(opt);
  });
}

/**
 * Update the match-count badge inside the search input wrapper.
 *
 * @param {string}  query          Current search query (empty = hide badge)
 * @param {number}  renderedCount  Matches visible in the current Cytoscape set
 * @param {number}  totalCount     Matches in the full _allNodes list
 */
function updateMatchCount(query, renderedCount, totalCount = 0) {
  let badge = document.getElementById('search-match-count');
  if (!badge) {
    const wrap = document.getElementById('search-input')?.parentElement;
    if (!wrap) return;
    badge = document.createElement('span');
    badge.id = 'search-match-count';
    wrap.appendChild(badge);
  }
  if (query) {
    // Show split count when there are more total matches than rendered
    const showSplit = totalCount > renderedCount && renderedCount > 0;
    badge.textContent = showSplit ? `${renderedCount}/${totalCount}` : `${renderedCount || totalCount}`;

    // Color semantics: slate = matches visible, yellow = expanding, red = none
    let color;
    if (renderedCount > 0) {
      color = 'text-slate-400';
    } else if (totalCount > 0) {
      color = 'text-yellow-400'; // matches exist outside focus, expansion pending
    } else {
      color = 'text-red-400';
    }
    badge.className = `absolute right-8 top-1/2 -translate-y-1/2 text-[10px] font-mono pointer-events-none ${color}`;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }
}

function bindKeyboardShortcuts() {
  document.addEventListener('keydown', (e) => {
    // Don't intercept when typing in inputs
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    if (e.key === 'Escape') {
      import('./sidebar.js').then(({ closeSidebar }) => closeSidebar());
      _cy && _cy.elements().removeClass('dimmed highlighted');
    }

    if (e.key === '/') {
      e.preventDefault();
      document.getElementById('search-input')?.focus();
    }

    if (e.key === 'f' || e.key === 'F') {
      _cy && _cy.fit();
    }

    // Keys 1-9 toggle type filters in order
    const num = parseInt(e.key);
    if (num >= 1 && num <= 9) {
      const btns = document.querySelectorAll('.type-filter-btn');
      if (btns[num - 1]) btns[num - 1].click();
    }
  });
}
