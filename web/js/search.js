/**
 * Search and type-filter controls for the Plugin Profiler graph.
 *
 * Public entry point: initSearch(cy, allNodes).
 * Wires the text search input, builds type-filter toggle buttons from the
 * live node set, and binds keyboard shortcuts (/, F, Esc, 1–9).
 */

import { nodeBadge } from './constants.js';

let _cy = null;
let _activeTypes = new Set();
let _allNodes = null;  // full node list (may exceed rendered set)
let _hideLibrary = false;

export function initSearch(cy, allNodes = null) {
  _cy = cy;
  _allNodes = allNodes;

  const allTypes = new Set(cy.nodes().map((n) => n.data('type')));
  _activeTypes = new Set(allTypes);

  buildTypeFilters(allTypes);
  populateAutocomplete();
  bindSearchInput();
  bindKeyboardShortcuts();
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
      applyFilters();
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
    btn.className = `type-filter-btn px-2 py-0.5 rounded text-xs text-white font-medium ${colorClass} opacity-100 transition-opacity`;
    btn.textContent = type.replace(/_/g, ' ');
    btn.dataset.type = type;
    btn.title = `Toggle ${type} nodes (${i + 1})`;

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
  } else {
    _activeTypes.add(type);
    btn.classList.remove('opacity-30');
  }
  applyFilters();
}

export function applyFilters() {
  if (!_cy) return;

  const query = (document.getElementById('search-input')?.value || '').toLowerCase().trim();

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
