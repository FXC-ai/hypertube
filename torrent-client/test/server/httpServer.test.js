import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from '../../src/server/httpServer.js';
import { DownloadManagerError } from '../../src/server/downloadManager.js';

function fakeManager(overrides = {}) {
  return {
    startDownload: async () => 'fixed-id',
    getStatus: () => null,
    cancelDownload: () => null,
    ...overrides,
  };
}

async function withServer(manager, fn) {
  const server = createServer({ manager });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address();
  try {
    return await fn(`http://127.0.0.1:${port}`);
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
}

test('GET /health returns 200 without touching the manager', async () => {
  const manager = fakeManager();
  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/health`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.status, 'ok');
  });
});

test('POST /downloads with a torrentUrl starts a download and returns 202 + id', async () => {
  let captured;
  const manager = fakeManager({
    startDownload: async (args) => {
      captured = args;
      return 'download-1';
    },
  });

  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/downloads`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ torrentUrl: 'https://example.com/x.torrent', outputDir: '/data/movies/1' }),
    });
    assert.equal(res.status, 202);
    const body = await res.json();
    assert.equal(body.id, 'download-1');
    assert.equal(captured.torrentUrl, 'https://example.com/x.torrent');
    assert.equal(captured.outputDir, '/data/movies/1');
  });
});

test('POST /downloads with torrentBase64 decodes it to raw bytes', async () => {
  let captured;
  const manager = fakeManager({
    startDownload: async (args) => {
      captured = args;
      return 'download-2';
    },
  });
  const raw = Buffer.from('d8:announce...e');

  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/downloads`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ torrentBase64: raw.toString('base64'), outputDir: '/data/movies/1' }),
    });
    assert.equal(res.status, 202);
    assert.ok(Buffer.isBuffer(captured.torrentBytes));
    assert.ok(captured.torrentBytes.equals(raw));
  });
});

test('POST /downloads returns 400 on invalid JSON', async () => {
  const manager = fakeManager();
  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/downloads`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{not json',
    });
    assert.equal(res.status, 400);
  });
});

test('POST /downloads returns 400 when the manager rejects with DownloadManagerError', async () => {
  const manager = fakeManager({
    startDownload: async () => {
      throw new DownloadManagerError('outputDir is required');
    },
  });
  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/downloads`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ torrentUrl: 'https://example.com/x.torrent' }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.match(body.error, /outputDir/);
  });
});

test('GET /downloads/:id returns the job status', async () => {
  const manager = fakeManager({
    getStatus: (id) => (id === 'download-1' ? { id, status: 'downloading', piecesCompleted: 2, totalPieces: 10 } : null),
  });
  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/downloads/download-1`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.status, 'downloading');
    assert.equal(body.piecesCompleted, 2);
  });
});

test('GET /downloads/:id returns 404 for an unknown id', async () => {
  const manager = fakeManager();
  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/downloads/nope`);
    assert.equal(res.status, 404);
  });
});

test('DELETE /downloads/:id cancels and returns the resulting status', async () => {
  let cancelledId;
  const manager = fakeManager({
    cancelDownload: (id) => {
      cancelledId = id;
      return { id, status: 'cancelled' };
    },
  });
  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/downloads/download-1`, { method: 'DELETE' });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.status, 'cancelled');
    assert.equal(cancelledId, 'download-1');
  });
});

test('DELETE /downloads/:id returns 404 for an unknown id', async () => {
  const manager = fakeManager();
  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/downloads/nope`, { method: 'DELETE' });
    assert.equal(res.status, 404);
  });
});

test('unknown routes return 404', async () => {
  const manager = fakeManager();
  await withServer(manager, async (base) => {
    const res = await fetch(`${base}/not-a-route`);
    assert.equal(res.status, 404);
  });
});
