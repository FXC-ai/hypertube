import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createDownloadManager } from '../../src/server/downloadManager.js';
import { CancelledError } from '../../src/cancelledError.js';

function fakeTorrent({ pieceLength = 100, lastPieceLength = 40, pieceCount = 3, urlList = [] } = {}) {
  const totalLength = pieceLength * (pieceCount - 1) + lastPieceLength;
  return {
    infoHash: '01'.repeat(20),
    announce: 'udp://tracker.example:80',
    announceList: undefined,
    urlList,
    pieceLength,
    pieces: Array.from({ length: pieceCount }, (_, i) => `hash${i}`),
    totalLength,
    files: [{ path: 'movie.mp4', length: totalLength }],
  };
}

function deferred() {
  let resolve, reject;
  const promise = new Promise((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

test('cancelDownload aborts an in-progress download and it settles as cancelled', async () => {
  const torrent = fakeTorrent();
  const parseTorrentFileFn = () => torrent;
  const announceFn = async () => ({ peers: [{ ip: '127.0.0.1', port: 1 }] });
  const started = deferred();
  const downloadTorrentFn = (t, peers, options) => new Promise((resolve, reject) => {
    started.resolve();
    options.signal.addEventListener('abort', () => reject(new CancelledError()));
  });

  const manager = createDownloadManager({ parseTorrentFileFn, announceFn, downloadTorrentFn });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/tmp/out' });
  await started.promise;

  const afterCancel = manager.cancelDownload(id);
  assert.ok(afterCancel);

  await waitFor(() => manager.getStatus(id).status !== 'downloading');
  assert.equal(manager.getStatus(id).status, 'cancelled');
});

async function waitFor(predicate, { timeoutMs = 2000, intervalMs = 5 } = {}) {
  const start = Date.now();
  while (!predicate()) {
    if (Date.now() - start > timeoutMs) {
      throw new Error('waitFor timed out');
    }
    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }
}
