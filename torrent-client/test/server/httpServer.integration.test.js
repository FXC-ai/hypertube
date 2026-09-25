import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { createServer } from '../../src/server/httpServer.js';

// Real stack over HTTP: POST starts a real Sintel download, wait for some progress, then
// DELETE must actually stop it.
test(
  'starts a real download over HTTP, sees real progress, and DELETE cancels it',
  { timeout: 60000 },
  async () => {
    const server = createServer();
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const { port } = server.address();
    const base = `http://127.0.0.1:${port}`;
    const outputDir = await mkdtemp(join(tmpdir(), 'torrent-http-integration-'));

    try {
      const startRes = await fetch(`${base}/downloads`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          torrentUrl: 'https://webtorrent.io/torrents/sintel.torrent',
          outputDir,
        }),
      });
      assert.equal(startRes.status, 202);
      const { id } = await startRes.json();
      assert.equal(typeof id, 'string');

      const progressed = await pollUntil(
        () => fetch(`${base}/downloads/${id}`).then((r) => r.json()),
        (status) => status.status === 'failed' || status.piecesCompleted > 0,
        { timeoutMs: 45000 },
      );
      assert.notEqual(progressed.status, 'failed', progressed.error ?? '');
      assert.ok(progressed.piecesCompleted > 0);
      assert.ok(progressed.totalPieces > 0);
      assert.equal(progressed.status, 'downloading');

      const cancelRes = await fetch(`${base}/downloads/${id}`, { method: 'DELETE' });
      assert.equal(cancelRes.status, 200);

      const settled = await pollUntil(
        () => fetch(`${base}/downloads/${id}`).then((r) => r.json()),
        (status) => status.status !== 'downloading',
        { timeoutMs: 10000 },
      );
      assert.equal(settled.status, 'cancelled');

      const piecesAtCancel = settled.piecesCompleted;
      await new Promise((resolve) => setTimeout(resolve, 1000));
      const afterWait = await fetch(`${base}/downloads/${id}`).then((r) => r.json());
      assert.equal(
        afterWait.piecesCompleted,
        piecesAtCancel,
        'no further pieces should complete after cancellation',
      );
    } finally {
      await new Promise((resolve) => server.close(resolve));
      await rm(outputDir, { recursive: true, force: true });
    }
  },
);

async function pollUntil(fetchStatus, predicate, { timeoutMs = 20000, intervalMs = 300 } = {}) {
  const start = Date.now();

  for (;;) {
    const status = await fetchStatus();

    if (predicate(status)) {
      return status;
    }

    if (Date.now() - start > timeoutMs) {
      throw new Error(`pollUntil timed out; last status: ${JSON.stringify(status)}`);
    }

    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }
}
