import { existsSync, readFileSync } from 'node:fs';
import { createServer as createHttpServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { TorrentFileError } from '../torrentFile.js';
import {
  createDownloadManager,
  DownloadManagerError,
  TorrentFetchError,
} from './downloadManager.js';

const DOWNLOAD_ID_PATTERN = /^\/downloads\/([^/]+)$/;

// In Docker the shared volume exists: pre-fill the page with the path Laravel reads from.
const DOCKER_STORAGE_DIR = '/var/www/html/storage';
const DOCKER_OUTPUT_DIR = `${DOCKER_STORAGE_DIR}/app/public/movies/999`;
const UI_TEMPLATE = readFileSync(new URL('./ui.html', import.meta.url), 'utf8');

function renderUiPage() {
  const outputDir = existsSync(DOCKER_STORAGE_DIR)
    ? DOCKER_OUTPUT_DIR
    : join(tmpdir(), 'hypertube-download');

  return UI_TEMPLATE.replace('__DEFAULT_OUTPUT_DIR__', outputDir);
}

// Plain node:http, no framework. The manager is injectable for tests.
export function createServer({ manager = createDownloadManager() } = {}) {
  return createHttpServer((req, res) => {
    handleRequest(req, res, manager).catch((err) => {
      sendJson(res, 500, { error: err.message });
    });
  });
}

async function handleRequest(req, res, manager) {
  if (req.method === 'GET' && req.url === '/health') {
    sendJson(res, 200, { status: 'ok' });

    return;
  }

  if (req.method === 'GET' && req.url === '/') {
    const page = renderUiPage();
    res.writeHead(200, {
      'Content-Type': 'text/html; charset=utf-8',
      'Content-Length': Buffer.byteLength(page),
    });
    res.end(page);

    return;
  }

  if (req.method === 'POST' && req.url === '/torrents/inspect') {
    await handleInspect(req, res, manager);

    return;
  }

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

async function handleInspect(req, res, manager) {
  const body = await readJsonBody(req, res);

  if (body === undefined) {
    return;
  }

  try {
    sendJson(res, 200, await manager.inspectTorrent(torrentSource(body)));
  } catch (err) {
    if (err instanceof TorrentFetchError) {
      sendJson(res, 502, { error: err.message });

      return;
    }

    if (err instanceof DownloadManagerError || err instanceof TorrentFileError) {
      sendJson(res, 400, { error: err.message });

      return;
    }

    throw err;
  }
}

async function handleStart(req, res, manager) {
  const body = await readJsonBody(req, res);

  if (body === undefined) {
    return;
  }

  const { outputDir, fileIndexes, expectedInfoHash } = body;

  try {
    const id = await manager.startDownload({
      ...torrentSource(body),
      outputDir,
      fileIndexes,
      expectedInfoHash,
    });
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

// Answers 400 itself and returns undefined when the body is not valid JSON.
async function readJsonBody(req, res) {
  try {
    const raw = await readBody(req);
    const body = raw.length > 0 ? JSON.parse(raw) : {};

    return body ?? {};
  } catch {
    sendJson(res, 400, { error: 'Invalid JSON body' });

    return undefined;
  }
}

function torrentSource({ torrentUrl, torrentBase64 }) {
  return {
    torrentUrl,
    torrentBytes: torrentBase64 === undefined ? undefined : Buffer.from(torrentBase64, 'base64'),
  };
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
