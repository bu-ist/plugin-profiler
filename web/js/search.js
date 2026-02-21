let _cy = null;
let _activeTypes = new Set();
let _allNodes = null;  // full node list (may exceed rendered set)

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

  const TYPE_COLORS = {
    // PHP / WordPress
    class:            'bg-blue-600',
    interface:        'bg-blue-500',
    trait:            'bg-blue-400',
    function:         'bg-teal-600',
    method:           'bg-teal-500',
    hook:             'bg-orange-500',
    rest_endpoint:    'bg-green-600',
    ajax_handler:     'bg-green-500',
    shortcode:        'bg-green-400',
    admin_page:       'bg-green-700',
    cron_job:         'bg-green-700',
    post_type:        'bg-green-800',
    taxonomy:         'bg-green-800',
    data_source:      'bg-purple-500',
    http_call:        'bg-red-500',
    file:             'bg-gray-500',
    // Gutenberg / WP JS
    gutenberg_block:  'bg-pink-500',
    js_hook:          'bg-orange-400',
    js_api_call:      'bg-green-500',
    js_function:      'bg-teal-500',
    js_class:         'bg-blue-500',
    // React / LAMP frontend
    react_component:  'bg-cyan-500',
    react_hook:       'bg-violet-500',
    fetch_call:       'bg-rose-500',
    axios_call:       'bg-rose-400',
  };

  [...allTypes].sort().forEach((type, i) => {
    const btn = document.createElement('button');
    const colorClass = TYPE_COLORS[type] || 'bg-gray-500';
    btn.className = `type-filter-btn px-2 py-0.5 rounded text-xs text-white font-medium ${colorClass} opacity-100 transition-opacity`;
    btn.textContent = type.replace(/_/g, ' ');
    btn.dataset.type = type;
    btn.title = `Toggle ${type} nodes (${i + 1})`;

    btn.addEventListener('click', () => toggleType(type, btn));
    container.appendChild(btn);
  });
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

function applyFilters() {
  if (!_cy) return;

  const query = (document.getElementById('search-input')?.value || '').toLowerCase().trim();

  _cy.batch(() => {
    _cy.nodes().forEach((node) => {
      const type = node.data('type');
      const label = (node.data('label') || '').toLowerCase();
      const typeVisible = _activeTypes.has(type);
      const searchMatch = !query || label.includes(query);

      if (typeVisible && searchMatch) {
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
