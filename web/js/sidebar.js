const TYPE_BADGE_CLASSES = {
  class:           'bg-blue-500',
  interface:       'bg-blue-400',
  trait:           'bg-blue-300 text-gray-800',
  function:        'bg-teal-500',
  method:          'bg-teal-400',
  hook:            'bg-orange-500',
  js_hook:         'bg-orange-400',
  rest_endpoint:   'bg-green-500',
  ajax_handler:    'bg-green-400',
  shortcode:       'bg-green-300 text-gray-800',
  admin_page:      'bg-green-600',
  cron_job:        'bg-green-700',
  post_type:       'bg-green-800',
  taxonomy:        'bg-green-800',
  data_source:     'bg-purple-500',
  http_call:       'bg-red-500',
  file:            'bg-gray-500',
  gutenberg_block: 'bg-pink-500',
  js_api_call:     'bg-green-500',
  js_function:     'bg-teal-500',
  js_class:        'bg-blue-500',
};

const JS_TYPES = new Set(['js_hook', 'js_api_call', 'js_function', 'js_class', 'gutenberg_block']);

let _cy = null;

export function initSidebar(cy) {
  _cy = cy;
}

export function openSidebar(nodeData) {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  sidebar.innerHTML = buildSidebarHtml(nodeData);
  sidebar.classList.remove('hidden');
  sidebar.classList.add('flex');

  // Re-highlight code after rendering
  if (window.Prism) {
    sidebar.querySelectorAll('code[class*="language-"]').forEach((el) => {
      Prism.highlightElement(el);
    });
  }

  // Wire connection clicks
  sidebar.querySelectorAll('[data-node-id]').forEach((el) => {
    el.addEventListener('click', () => {
      const id = el.getAttribute('data-node-id');
      if (_cy) {
        const target = _cy.getElementById(id);
        if (target.length) {
          _cy.animate({ fit: { eles: target.closedNeighborhood(), padding: 60 } }, { duration: 300 });
          target.emit('tap');
        }
      }
    });
  });
}

export function closeSidebar() {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  sidebar.classList.add('hidden');
  sidebar.classList.remove('flex');
}

function buildSidebarHtml(data) {
  const badgeClass = TYPE_BADGE_CLASSES[data.type] || 'bg-gray-500';
  const subtype = data.subtype ? ` / ${data.subtype}` : '';
  const language = JS_TYPES.has(data.type) ? 'javascript' : 'php';

  const connections = buildConnectionsHtml(data);
  const vscodePath = buildVsCodeLink(data);
  const sourceHtml = data.source_preview
    ? `<pre class="text-xs overflow-x-auto"><code class="language-${language}">${escapeHtml(data.source_preview)}</code></pre>`
    : '<p class="text-gray-400 text-xs italic">No source preview available.</p>';

  const docblockHtml = data.docblock
    ? `<div class="mt-3 p-2 bg-gray-800 rounded text-xs text-gray-300 font-mono whitespace-pre-wrap">${escapeHtml(data.docblock)}</div>`
    : '';

  return `
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2 min-w-0">
        <span class="shrink-0 px-2 py-0.5 rounded text-xs font-semibold text-white ${badgeClass}">${escapeHtml(data.type)}${escapeHtml(subtype)}</span>
        <span class="font-semibold text-white truncate" title="${escapeHtml(data.label)}">${escapeHtml(data.label)}</span>
      </div>
      <button id="sidebar-close" class="text-gray-400 hover:text-white shrink-0 ml-2" aria-label="Close">&#x2715;</button>
    </div>

    ${data.description
      ? `<p class="text-sm text-gray-300 mb-3">${escapeHtml(data.description)}</p>`
      : '<p class="text-xs text-gray-500 italic mb-3">No AI description generated.</p>'}

    <div class="text-xs text-gray-400 mb-3">
      <span class="text-gray-500">File:</span>
      ${vscodePath
        ? `<a href="${vscodePath}" class="text-blue-400 hover:underline break-all">${escapeHtml(data.file)}:${data.line}</a>`
        : `<span class="break-all">${escapeHtml(data.file)}:${data.line}</span>`}
    </div>

    ${connections}
    ${docblockHtml}

    <div class="mt-3 text-xs text-gray-500 font-semibold uppercase tracking-wide mb-1">Source Preview</div>
    ${sourceHtml}

    <script>
      document.getElementById('sidebar-close')?.addEventListener('click', () => {
        document.getElementById('sidebar').classList.add('hidden');
        document.getElementById('sidebar').classList.remove('flex');
      });
    </script>
  `;
}

function buildConnectionsHtml(data) {
  if (!_cy) return '';

  const node = _cy.getElementById(data.id);
  if (!node || !node.length) return '';

  const edges = node.connectedEdges();
  if (!edges.length) return '';

  const groups = {};
  edges.forEach((edge) => {
    const type = edge.data('type');
    const isOutgoing = edge.data('source') === data.id;
    const otherId = isOutgoing ? edge.data('target') : edge.data('source');
    const otherNode = _cy.getElementById(otherId);
    const otherLabel = otherNode.length ? otherNode.data('label') : otherId;

    const key = `${isOutgoing ? '→' : '←'} ${type}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push({ id: otherId, label: otherLabel });
  });

  const html = Object.entries(groups).map(([groupKey, nodes]) => {
    const items = nodes.map(({ id, label }) =>
      `<li><button class="text-blue-400 hover:underline text-xs text-left" data-node-id="${escapeHtml(id)}">${escapeHtml(label)}</button></li>`
    ).join('');
    return `<div class="mb-2">
      <div class="text-xs text-gray-500 font-semibold mb-1">${escapeHtml(groupKey)}</div>
      <ul class="list-none space-y-0.5 pl-2">${items}</ul>
    </div>`;
  }).join('');

  return `<div class="mt-2 mb-3">
    <div class="text-xs text-gray-500 font-semibold uppercase tracking-wide mb-2">Connections</div>
    ${html}
  </div>`;
}

function buildVsCodeLink(data) {
  if (!data.file) return null;
  return `vscode://file/${data.file}:${data.line || 0}`;
}

function escapeHtml(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
