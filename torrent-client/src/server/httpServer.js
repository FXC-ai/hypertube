import { createServer as createHttpServer } from 'node:http';
import { createDownloadManager, DownloadManagerError } from './downloadManager.js';

const DOWNLOAD_ID_PATTERN = /^\/downloads\/([^/]+)$/;

// start/status/cancel over plain node:http -- no framework, matching the
// rest of this service. The manager is injectable purely for testing; in
// production a single createDownloadManager() instance backs the server.
export function createServer({ manager = createDownloadManager() } = {}) {
  return createHttpServer((req, res) => {
    handleRequest(req, res, manager).catch((err) => {
      sendJson(res, 500, { error: err.message });
    });
  });
}

async function handleRequest(req, res, manager) {
  if (req.method === 'POST' && req.url === '/downloads') {
    await handleStart(req, res, manager);
    return;
  }

  const idMatch = req.url?.match(DOWNLOAD_ID_PATTERN);
  if (idMatch && req.method === 'GET') {
    handleStatus(res, manager, decodeURIComponent(idMatch[1]));
    return;
  }
  if (idMatch && req.method === 'DELETE') {
    handleCancel(res, manager, decodeURIComponent(idMatch[1]));
    return;
  }

  sendJson(res, 404, { error: 'Not found' });
}

async function handleStart(req, res, manager) {
  let body;
  try {
    const raw = await readBody(req);
    body = raw.length > 0 ? JSON.parse(raw) : {};
  } catch {
    sendJson(res, 400, { error: 'Invalid JSON body' });
    return;
  }

  const { torrentUrl, torrentBase64, outputDir } = body ?? {};
  let torrentBytes;
  if (torrentBase64 !== undefined) {
    torrentBytes = Buffer.from(torrentBase64, 'base64');
  }

  try {
    const id = await manager.startDownload({ torrentBytes, torrentUrl, outputDir });
    sendJson(res, 202, { id });
  } catch (err) {
    if (err instanceof DownloadManagerError) {
      sendJson(res, 400, { error: err.message });
      return;
    }
    throw err;
  }
}

function handleStatus(res, manager, id) {
  const status = manager.getStatus(id);
  if (!status) {
    sendJson(res, 404, { error: 'Unknown download id' });
    return;
  }
  sendJson(res, 200, status);
}

function handleCancel(res, manager, id) {
  const status = manager.cancelDownload(id);
  if (!status) {
    sendJson(res, 404, { error: 'Unknown download id' });
    return;
  }
  sendJson(res, 200, status);
}

function sendJson(res, statusCode, body) {
  const payload = JSON.stringify(body);
  res.writeHead(statusCode, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(payload),
  });
  res.end(payload);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (chunk) => chunks.push(chunk));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}
