import { nodeBadge } from './constants.js';
import { escapeHtml } from './utils.js';

// All node types that originate from JavaScript files — these get JS syntax
// highlighting in the source preview block instead of PHP highlighting.
const JS_TYPES = new Set([
  'js_hook', 'js_api_call', 'js_function', 'js_class',
  'gutenberg_block', 'react_component', 'react_hook',
  'fetch_call', 'axios_call',
]);

let _cy         = null;
let _pluginData = null;  // Plugin block from graph-data.json (may include summary)

export function initSidebar(cy) {
  _cy = cy;
}

/**
 * Store the plugin metadata block and, if it contains an AI-generated summary,
 * display it as the sidebar's default state. Calling this replaces any
 * previously open node panel.
 */
export function openPluginSummary(plugin) {
  _pluginData = plugin;
  if (!plugin?.summary) return;  // No summary — keep sidebar hidden

  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  sidebar.innerHTML = buildPluginSummaryHtml(plugin);
  sidebar.classList.remove('hidden');
  sidebar.classList.add('flex');
  sidebar.removeAttribute('aria-hidden');
}

export function openSidebar(nodeData) {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  sidebar.innerHTML = buildSidebarHtml(nodeData);
  sidebar.classList.remove('hidden');
  sidebar.classList.add('flex');
  sidebar.removeAttribute('aria-hidden');

  // Re-highlight code after rendering
  if (window.Prism) {
    sidebar.querySelectorAll('code[class*="language-"]').forEach((el) => {
      try {
        Prism.highlightElement(el);
      } catch (_) {
        // Prism can fail on certain PHP constructs — leave as plain text
      }
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
  // When a plugin summary is available, closing a node panel reverts to it
  // rather than hiding the sidebar entirely.
  if (_pluginData?.summary) {
    openPluginSummary(_pluginData);
    return;
  }

  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  sidebar.classList.add('hidden');
  sidebar.classList.remove('flex');
  sidebar.setAttribute('aria-hidden', 'true');
}

/**
 * Render a compact sidebar for compound group nodes (namespace / directory).
 * These nodes carry no file, line, or source_preview — only a label and type.
 * We derive a member list from the live Cytoscape graph when available.
 */
function buildGroupSidebarHtml(data) {
  const badgeClass = nodeBadge(data.type);
  const icon       = data.type === 'namespace' ? '⬡' : '⊞';

  // Members are visible children of the compound node. When the group is
  // collapsed the expand-collapse extension hides them, so we get zero — the
  // "expand to view members" hint below handles that case gracefully.
  const children   = _cy ? _cy.getElementById(data.id).children() : null;
  const count      = children ? children.length : 0;

  const childItems = count > 0
    ? children.map((c) =>
        `<li><button class="text-blue-400 hover:underline text-xs text-left" data-node-id="${escapeHtml(c.id())}">${escapeHtml(c.data('label'))}</button></li>`
      ).join('')
    : '<li class="text-gray-500 text-xs italic">Expand the group to view members.</li>';

  return `
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2 min-w-0">
        <span class="shrink-0 px-2 py-0.5 rounded text-xs font-semibold text-white ${badgeClass}">${escapeHtml(data.type)}</span>
        <span class="font-semibold text-white truncate" title="${escapeHtml(data.label)}">${icon} ${escapeHtml(data.label)}</span>
      </div>
      <button id="sidebar-close" class="text-gray-400 hover:text-white shrink-0 ml-2" aria-label="Close">&#x2715;</button>
    </div>

    <div class="mt-2 mb-3">
      <div class="text-xs text-gray-500 font-semibold uppercase tracking-wide mb-2">
        Members${count > 0 ? ` (${count})` : ''}
      </div>
      <ul class="list-none space-y-0.5 pl-2">${childItems}</ul>
    </div>
  `;
}

function buildSidebarHtml(data) {
  // Compound namespace / directory groups get a minimal dedicated view.
  if (data.type === 'namespace' || data.type === 'dir') {
    return buildGroupSidebarHtml(data);
  }

  const badgeClass = nodeBadge(data.type);
  const subtype = data.subtype ? ` / ${data.subtype}` : '';
  const language = JS_TYPES.has(data.type) ? 'javascript' : 'php';

  const connections    = buildConnectionsHtml(data);
  const securityBadges = buildSecurityBadgesHtml(data);
  const copyPath       = buildCopyPath(data);
  // Show absolute host path when available; otherwise strip the /plugin container prefix.
  const displayFile = (copyPath && !copyPath.startsWith('/plugin'))
    ? copyPath   // already includes :line suffix
    : (data.file?.replace(/^\/plugin\//, '') ?? data.file) + (data.line ? `:${data.line}` : '');
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

    ${securityBadges}

    ${data.description
      ? `<div class="mb-3 rounded-lg border border-indigo-800/40 bg-indigo-950/50 p-3">
           <div class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-indigo-400">
             <span aria-hidden="true">✦</span><span>AI Insight</span>
           </div>
           <p class="text-sm leading-relaxed text-slate-200">${escapeHtml(data.description)}</p>
         </div>`
      : '<p class="text-xs text-gray-500 italic mb-3">No AI description — add <code class="text-gray-400">--descriptions</code> to generate.</p>'}

    <div class="text-xs text-gray-400 mb-3">
      <span class="text-gray-500">File:</span>
      ${copyPath
        ? `<button data-copy-path="${escapeHtml(copyPath)}" class="text-blue-400 hover:text-blue-300 break-all text-left cursor-pointer" title="Click to copy path to clipboard">${escapeHtml(displayFile)}</button>`
        : `<span class="break-all">${escapeHtml(displayFile)}</span>`}
    </div>

    ${connections}
    ${docblockHtml}

    <div class="mt-3 text-xs text-gray-500 font-semibold uppercase tracking-wide mb-1">Source Preview</div>
    ${sourceHtml}
  `;
}

/**
 * Format edge metadata into a compact inline annotation.
 * Returns an HTML string (or empty string when no metadata is present).
 */
function formatEdgeMeta(metadata) {
  if (!metadata || typeof metadata !== 'object') return '';

  const parts = [];

  if (metadata.api_function) {
    parts.push(`via ${metadata.api_function}`);
  }
  if (metadata.priority != null && metadata.priority !== 10) {
    parts.push(`priority ${metadata.priority}`);
  }
  if (metadata.hook_type) {
    parts.push(metadata.hook_type);
  }
  if (metadata.http_method) {
    parts.push(metadata.http_method);
  }

  if (parts.length === 0) return '';
  return ` <span class="text-gray-500 text-xs">(${escapeHtml(parts.join(', '))})</span>`;
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
    const edgeMeta = edge.data('metadata') || null;

    const key = `${isOutgoing ? '→' : '←'} ${type}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push({ id: otherId, label: otherLabel, metadata: edgeMeta });
  });

  const html = Object.entries(groups).map(([groupKey, items]) => {
    const listItems = items.map(({ id, label, metadata }) => {
      const annotation = formatEdgeMeta(metadata);
      return `<li><button class="text-blue-400 hover:underline text-xs text-left" data-node-id="${escapeHtml(id)}">${escapeHtml(label)}</button>${annotation}</li>`;
    }).join('');
    return `<div class="mb-2">
      <div class="text-xs text-gray-500 font-semibold mb-1">${escapeHtml(groupKey)}</div>
      <ul class="list-none space-y-0.5 pl-2">${listItems}</ul>
    </div>`;
  }).join('');

  return `<div class="mt-2 mb-3">
    <div class="text-xs text-gray-500 font-semibold uppercase tracking-wide mb-2">Connections</div>
    ${html}
  </div>`;
}

/**
 * Build a compact security badge bar for nodes with security annotations.
 * Shows capability, nonce verification, and sanitization status.
 * Returns empty string when no security metadata is present.
 */
function buildSecurityBadgesHtml(data) {
  const meta      = data.metadata ?? {};
  const cap       = meta.capability ?? null;
  const nonce     = meta.nonce_verified ?? false;
  const sanitize  = meta.sanitization_count ?? 0;

  // Only show for types that can have security context
  const secTypes = new Set(['rest_endpoint', 'ajax_handler', 'function', 'method']);
  if (!secTypes.has(data.type) || (cap === null && !nonce && sanitize === 0)) {
    return '';
  }

  const badges = [];

  if (cap !== null) {
    if (cap === '__return_true') {
      badges.push('<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-amber-900/60 text-amber-300 border border-amber-700/50">&#x26A0; Public (no auth)</span>');
    } else {
      badges.push(`<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-900/60 text-green-300 border border-green-700/50">&#x1F512; ${escapeHtml(cap)}</span>`);
    }
  }

  if (nonce) {
    badges.push('<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-blue-900/60 text-blue-300 border border-blue-700/50">&#x2713; Nonce</span>');
  } else if (secTypes.has(data.type) && (data.type === 'rest_endpoint' || data.type === 'ajax_handler')) {
    badges.push('<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-red-900/60 text-red-300 border border-red-700/50">&#x2717; No nonce</span>');
  }

  if (sanitize > 0) {
    badges.push(`<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-teal-900/60 text-teal-300 border border-teal-700/50">&#x1F9F9; ${sanitize} sanitize</span>`);
  }

  if (badges.length === 0) return '';

  return `<div class="flex flex-wrap gap-1.5 mb-3">${badges.join('')}</div>`;
}

/**
 * Returns the absolute host-side path (with line number) ready to copy to clipboard,
 * e.g. "/Users/foo/plugins/my-plugin/src/class-foo.php:42".
 * Uses host_path from plugin metadata to remap container-internal /plugin/... paths.
 * Returns null when no file path is available.
 */
function buildCopyPath(data) {
  if (!data.file) return null;

  let filePath = data.file;
  const hostPath = _pluginData?.host_path;
  if (hostPath && filePath.startsWith('/plugin')) {
    filePath = hostPath.replace(/\/$/, '') + filePath.slice('/plugin'.length);
  }

  return data.line ? `${filePath}:${data.line}` : filePath;
}

/**
 * Build HTML for the default plugin overview panel (shown when no node is selected).
 * The summary text is split on double-newlines to produce readable paragraphs.
 */
function buildPluginSummaryHtml(plugin) {
  const paragraphs = (plugin.summary || '')
    .split('\n\n')
    .map(p => p.trim())
    .filter(Boolean)
    .map(p => `<p class="text-sm leading-relaxed text-slate-200">${escapeHtml(p)}</p>`)
    .join('');

  return `
    <div class="mb-3">
      <div class="flex items-center gap-2 mb-1">
        <span class="px-2 py-0.5 rounded text-xs font-semibold text-white bg-indigo-600">Plugin</span>
        <span class="font-semibold text-white truncate" title="${escapeHtml(plugin.name || '')}">${escapeHtml(plugin.name || 'Unknown Plugin')}</span>
      </div>
      ${plugin.version ? `<div class="text-xs text-gray-500 mb-1">${escapeHtml(plugin.version)}</div>` : ''}
    </div>

    <div class="rounded-lg border border-indigo-800/40 bg-indigo-950/50 p-3">
      <div class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-indigo-400">
        <span aria-hidden="true">✦</span><span>AI Architecture Overview</span>
      </div>
      <div class="space-y-2">${paragraphs}</div>
    </div>

    <div class="text-xs text-gray-500 italic text-center mt-4">Click any node to inspect it.</div>
  `;
}

