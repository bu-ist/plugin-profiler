/**
 * Plugin Profiler — Controller service
 *
 * Tiny HTTP server that triggers a fresh analysis run from the browser UI.
 *
 * Endpoints:
 *   GET  /status   → { running: bool, analyzedAt: string|null }
 *   POST /analyze  { path: string, noDescriptions?: bool }
 *                  → 202 { status: 'started' }
 *                     409 { error: 'Analysis already running' }
 *                     400 { error: 'path must be an absolute path' }
 *
 * The server mounts /var/run/docker.sock and /project (the repo root) so
 * it can invoke `docker compose run` against the host daemon.
 */

import { createServer }         from 'node:http';
import { exec }                  from 'node:child_process';
import { readFile }              from 'node:fs/promises';
import { join }                  from 'node:path';

const PROJECT_DIR = '/project';
const PORT        = 9001;

let _running    = false;
let _analyzedAt = null;

// Try to read the current analyzedAt from graph-data.json on startup.
// This lets the UI detect that a new run completed even after a restart.
(async () => {
  try {
    const raw  = await readFile(join(PROJECT_DIR, 'output', 'graph-data.json'), 'utf8');
    const data = JSON.parse(raw);
    _analyzedAt = data?.plugin?.analyzed_at ?? null;
  } catch { /* file may not exist yet */ }
})();

function corsHeaders(res) {
  res.setHeader('Access-Control-Allow-Origin',  '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
}

async function readBody(req) {
  let body = '';
  for await (const chunk of req) body += chunk;
  return body ? JSON.parse(body) : {};
}

const server = createServer(async (req, res) => {
  res.setHeader('Content-Type', 'application/json');
  corsHeaders(res);

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  // GET /status
  if (req.method === 'GET' && req.url === '/status') {
    res.writeHead(200);
    res.end(JSON.stringify({ running: _running, analyzedAt: _analyzedAt }));
    return;
  }

  // POST /analyze
  if (req.method === 'POST' && req.url === '/analyze') {
    if (_running) {
      res.writeHead(409);
      res.end(JSON.stringify({ error: 'Analysis already running' }));
      return;
    }

    let body;
    try {
      body = await readBody(req);
    } catch {
      res.writeHead(400);
      res.end(JSON.stringify({ error: 'Invalid JSON body' }));
      return;
    }

    const { path, noDescriptions = true } = body;
    if (!path || !path.startsWith('/')) {
      res.writeHead(400);
      res.end(JSON.stringify({ error: 'path must be an absolute path (starts with /)' }));
      return;
    }

    _running = true;
    res.writeHead(202);
    res.end(JSON.stringify({ status: 'started' }));

    const flags = noDescriptions ? '--no-descriptions' : '';
    // Use PLUGIN_PATH env var so docker-compose.yml mounts the new path as /plugin
    const cmd = `docker compose run --rm -e PLUGIN_PATH=${JSON.stringify(path)} analyzer /plugin ${flags}`;
    exec(cmd, { cwd: PROJECT_DIR, env: { ...process.env, PLUGIN_PATH: path } }, async (err) => {
      _running = false;
      if (!err) {
        // Read the new analyzedAt from the freshly-written graph-data.json
        try {
          const raw  = await readFile(join(PROJECT_DIR, 'output', 'graph-data.json'), 'utf8');
          const data = JSON.parse(raw);
          _analyzedAt = data?.plugin?.analyzed_at ?? new Date().toISOString();
        } catch {
          _analyzedAt = new Date().toISOString();
        }
      }
    });
    return;
  }

  res.writeHead(404);
  res.end(JSON.stringify({ error: 'Not found' }));
});

server.listen(PORT, () => {
  console.log(`Plugin Profiler controller listening on :${PORT}`);
});
