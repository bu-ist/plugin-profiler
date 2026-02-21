#!/usr/bin/env node
/**
 * js-extractor.mjs
 *
 * Parses a JS/JSX/TS/TSX file with @babel/parser and emits a JSON array
 * of detected entities to stdout. One file path is read from argv[2].
 *
 * Output schema (array of objects):
 * {
 *   type:     string   — entity type key
 *   subtype:  string?  — refinement (e.g. hook kind, http method)
 *   name:     string   — display label
 *   line:     number   — 1-based source line
 *   meta:     object   — type-specific extra fields
 * }
 */

import { readFileSync } from 'fs';
import { parse } from '@babel/parser';

const filePath = process.argv[2];
if (!filePath) {
  process.stderr.write('Usage: js-extractor.mjs <file>\n');
  process.exit(1);
}

let source;
try {
  source = readFileSync(filePath, 'utf8');
} catch (e) {
  process.stderr.write(`Warning: Cannot read ${filePath}: ${e.message}\n`);
  process.stdout.write('[]\n');
  process.exit(0);
}

let ast;
try {
  ast = parse(source, {
    sourceType: 'unambiguous',
    plugins: [
      'jsx',
      'typescript',
      'classProperties',
      'classPrivateProperties',
      'classPrivateMethods',
      'optionalChaining',
      'nullishCoalescingOperator',
      'dynamicImport',
      'importMeta',
      'exportDefaultFrom',
      'decorators',
    ],
    errorRecovery: true,   // keep going even with partial syntax errors
  });
} catch (e) {
  process.stderr.write(`Warning: JS parse error in ${filePath}: ${e.message}\n`);
  process.stdout.write('[]\n');
  process.exit(0);
}

const entities = [];

// ── Helpers ──────────────────────────────────────────────────────────────────

function line(node) {
  return node?.loc?.start?.line ?? 0;
}

/** Flatten a MemberExpression or Identifier to a dotted string, e.g. "wp.hooks.addAction" */
function memberName(node) {
  if (!node) return null;
  if (node.type === 'Identifier') return node.name;
  if (node.type === 'MemberExpression') {
    const obj = memberName(node.object);
    const prop = node.property?.name ?? null;
    if (obj && prop) return `${obj}.${prop}`;
    return prop;
  }
  return null;
}

/** Extract a string literal value, or null */
function strVal(node) {
  if (!node) return null;
  if (node.type === 'StringLiteral') return node.value;
  if (node.type === 'TemplateLiteral' && node.quasis.length === 1) {
    return node.quasis[0].value.cooked ?? null;
  }
  return null;
}

/** Get the value of a named key in an ObjectExpression */
function objProp(node, key) {
  if (!node || node.type !== 'ObjectExpression') return null;
  for (const prop of node.properties ?? []) {
    if (prop.type !== 'ObjectProperty' && prop.type !== 'Property') continue;
    const k = prop.key?.name ?? prop.key?.value ?? null;
    if (k === key) return prop.value;
  }
  return null;
}

/** Is a node an arrow/regular function expression? */
function isFunctionExpr(node) {
  return node && (
    node.type === 'ArrowFunctionExpression' ||
    node.type === 'FunctionExpression'
  );
}

/** Returns JSX element name string (tag or component name) from JSXOpeningElement */
function jsxName(node) {
  if (!node) return null;
  if (node.type === 'JSXIdentifier') return node.name;
  if (node.type === 'JSXMemberExpression') {
    const obj = jsxName(node.object);
    const prop = node.property?.name ?? null;
    return obj && prop ? `${obj}.${prop}` : prop;
  }
  return null;
}

/** True if JSX element name starts with an uppercase letter (React component) */
function isComponentRef(name) {
  return name && /^[A-Z]/.test(name);
}

// ── React component detection helpers ────────────────────────────────────────

/**
 * Determine if a node is a React function component body.
 * Heuristic: function that returns JSX (JSXElement / JSXFragment).
 */
function returnsJsx(node) {
  if (!node) return false;
  // Arrow with expression body: () => <Foo />
  // Babel does NOT set node.expression=true; just check whether body is JSX directly.
  if (node.type === 'ArrowFunctionExpression') {
    const b = node.body;
    if (b && (b.type === 'JSXElement' || b.type === 'JSXFragment')) return true;
  }
  // Block body: search top-level ReturnStatements
  const body = node.body;
  if (!body || body.type !== 'BlockStatement') return false;
  return body.body.some(stmt => {
    if (stmt.type === 'ReturnStatement') {
      const arg = stmt.argument;
      return arg && (arg.type === 'JSXElement' || arg.type === 'JSXFragment' ||
                     arg.type === 'ConditionalExpression');
    }
    return false;
  });
}

// ── Walk the AST ─────────────────────────────────────────────────────────────

function walk(node, parent) {
  if (!node || typeof node !== 'object') return;

  switch (node.type) {

    // ── Function declarations ──────────────────────────────────────────────
    case 'FunctionDeclaration': {
      const name = node.id?.name;
      if (name) {
        if (returnsJsx(node)) {
          entities.push({ type: 'react_component', name, line: line(node), meta: {} });
        } else {
          entities.push({ type: 'js_function', name, line: line(node), meta: {} });
        }
      }
      break;
    }

    // ── Class declarations ─────────────────────────────────────────────────
    case 'ClassDeclaration': {
      const name = node.id?.name;
      if (name) {
        const superClass = memberName(node.superClass);
        entities.push({ type: 'js_class', name, line: line(node), meta: { extends: superClass } });
      }
      break;
    }

    // ── Variable declarations: const Foo = () => <div/> ───────────────────
    case 'VariableDeclaration': {
      for (const decl of node.declarations ?? []) {
        const name = decl.id?.name;
        if (!name || !isFunctionExpr(decl.init)) continue;
        if (returnsJsx(decl.init)) {
          entities.push({ type: 'react_component', name, line: line(decl), meta: {} });
        }
        // Don't add as js_function here — avoids double-counting with CallExpression handlers
      }
      break;
    }

    // ── Call expressions ───────────────────────────────────────────────────
    case 'CallExpression': {
      const callee = node.callee;
      const name   = memberName(callee);
      const args   = node.arguments ?? [];
      const l      = line(node);

      // ── WordPress: registerBlockType ──
      if (name === 'registerBlockType') {
        const blockName = strVal(args[0]);
        if (blockName) {
          entities.push({ type: 'gutenberg_block', name: blockName, line: l, meta: { block_name: blockName } });
        }
      }

      // ── WordPress: wp.hooks.addAction / addFilter (and bare addAction/addFilter) ──
      else if (name === 'addAction' || name === 'wp.hooks.addAction') {
        const hookName = strVal(args[0]);
        if (hookName) {
          entities.push({ type: 'js_hook', subtype: 'action', name: hookName, line: l, meta: { hook_name: hookName } });
        }
      }
      else if (name === 'addFilter' || name === 'wp.hooks.addFilter') {
        const hookName = strVal(args[0]);
        if (hookName) {
          entities.push({ type: 'js_hook', subtype: 'filter', name: hookName, line: l, meta: { hook_name: hookName } });
        }
      }

      // ── WordPress: apiFetch ──
      else if (name === 'apiFetch') {
        const opts   = args[0];
        const path   = strVal(objProp(opts, 'path'));
        const method = strVal(objProp(opts, 'method'))?.toUpperCase() ?? 'GET';
        if (path) {
          entities.push({ type: 'js_api_call', name: `${method} ${path}`, line: l,
            meta: { http_method: method, route: path } });
        }
      }

      // ── fetch() ──────────────────────────────────────────────────────────
      else if (name === 'fetch') {
        const url    = strVal(args[0]);
        const opts   = args[1];
        const method = strVal(objProp(opts, 'method'))?.toUpperCase() ?? 'GET';
        const label  = url ? `${method} ${url}` : `${method} (dynamic)`;
        entities.push({ type: 'fetch_call', name: label, line: l,
          meta: { http_method: method, route: url ?? null } });
      }

      // ── axios.get / axios.post / axios.put / axios.delete / axios.patch ──
      else if (name && /^axios\.(get|post|put|delete|patch|request|head)$/i.test(name)) {
        const method = name.split('.')[1].toUpperCase();
        const url    = strVal(args[0]);
        const label  = url ? `${method} ${url}` : `${method} (dynamic)`;
        entities.push({ type: 'axios_call', subtype: method.toLowerCase(), name: label, line: l,
          meta: { http_method: method, route: url ?? null } });
      }

      // ── axios(config) — bare call ─────────────────────────────────────────
      else if (name === 'axios') {
        const cfg    = args[0];
        const method = strVal(objProp(cfg, 'method'))?.toUpperCase() ?? 'GET';
        const url    = strVal(objProp(cfg, 'url'));
        const label  = url ? `${method} ${url}` : `${method} (dynamic)`;
        entities.push({ type: 'axios_call', subtype: method.toLowerCase(), name: label, line: l,
          meta: { http_method: method, route: url ?? null } });
      }

      // ── React hooks ───────────────────────────────────────────────────────
      else if (name === 'useState') {
        // Find enclosing component name via parent chain — best effort, record at call site
        entities.push({ type: 'react_hook', subtype: 'useState', name: 'useState', line: l, meta: {} });
      }
      else if (name === 'useEffect') {
        entities.push({ type: 'react_hook', subtype: 'useEffect', name: 'useEffect', line: l, meta: {} });
      }
      else if (name === 'useContext') {
        const ctxName = memberName(args[0]) ?? strVal(args[0]) ?? 'unknown';
        entities.push({ type: 'react_hook', subtype: 'useContext', name: `useContext(${ctxName})`, line: l, meta: {} });
      }
      else if (name && /^use[A-Z]/.test(name)) {
        // Custom hooks — any call matching use* naming convention
        entities.push({ type: 'react_hook', subtype: 'custom', name, line: l, meta: {} });
      }

      break;
    }

    // ── Import declarations ────────────────────────────────────────────────
    case 'ImportDeclaration': {
      const src = strVal(node.source);
      if (!src) break;
      // Track all non-relative, non-asset imports as dependency edges
      if (!src.startsWith('.') && !src.startsWith('/') && !/\.(css|scss|less|svg|png|jpg|json)$/.test(src)) {
        entities.push({ type: 'js_import', name: src, line: line(node), meta: { source: src } });
      }
      break;
    }

    // ── Export declarations: export default function Foo() ─────────────────
    case 'ExportDefaultDeclaration': {
      const decl = node.declaration;
      if (decl?.type === 'FunctionDeclaration' || decl?.type === 'ClassDeclaration') {
        // Will be caught when we recurse into the declaration — no duplicate needed
      } else if (isFunctionExpr(decl) && returnsJsx(decl)) {
        // export default () => <div/>  — anonymous default export
        entities.push({ type: 'react_component', name: '(default export)', line: line(node), meta: {} });
      }
      break;
    }
  }

  // Recurse into children
  for (const key of Object.keys(node)) {
    if (key === 'type' || key === 'loc' || key === 'start' || key === 'end' || key === 'extra') continue;
    const child = node[key];
    if (Array.isArray(child)) {
      for (const c of child) {
        if (c && typeof c === 'object' && c.type) walk(c, node);
      }
    } else if (child && typeof child === 'object' && child.type) {
      walk(child, node);
    }
  }
}

walk(ast.program, null);

process.stdout.write(JSON.stringify(entities) + '\n');
