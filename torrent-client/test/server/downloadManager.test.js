import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CancelledError } from '../../src/cancelledError.js';
import {
  createDownloadManager,
  DownloadManagerError,
  TorrentFetchError,
} from '../../src/server/downloadManager.js';

function fakeTorrent({
  pieceLength = 100,
  lastPieceLength = 40,
  pieceCount = 3,
  urlList = [],
} = {}) {
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
  const downloadTorrentFn = (t, peers, options) =>
    new Promise((resolve, reject) => {
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

// meta.sqlite [0,50), movie.mp4 [50,230), movie.en.srt [230,240): pieces of 100 bytes, so
// piece 0 is shared by meta.sqlite and movie.mp4, piece 2 by movie.mp4 and the subtitle.
function multiFileTorrent() {
  return {
    ...fakeTorrent({ pieceCount: 3, lastPieceLength: 40 }),
    files: [
      { path: 'meta.sqlite', length: 50 },
      { path: 'movie.mp4', length: 180 },
      { path: 'movie.en.srt', length: 10 },
    ],
  };
}

// Stands in for downloadTorrent: reports `pieceIndexes` as done, in order.
function completingDownload(calls, pieceIndexes) {
  return async (torrent, peers, options) => {
    calls.push(options);
    pieceIndexes.forEach((pieceIndex, i) =>
      options.onProgress({ completed: i + 1, total: pieceIndexes.length, pieceIndex }),
    );
  };
}

function managerFor(torrent, overrides = {}) {
  return createDownloadManager({
    parseTorrentFileFn: () => torrent,
    announceFn: async () => ({ peers: [{ ip: '127.0.0.1', port: 1 }] }),
    ...overrides,
  });
}

test('without fileIndexes, the suggested files are downloaded and progress is counted per file', async () => {
  const calls = [];
  const manager = managerFor(multiFileTorrent(), {
    downloadTorrentFn: completingDownload(calls, [0, 1, 2]),
  });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/tmp/out' });
  await waitFor(() => manager.getStatus(id).status !== 'downloading');

  const status = manager.getStatus(id);
  assert.equal(status.status, 'completed', status.error ?? '');
  assert.deepEqual(calls[0].fileIndexes, [1, 2]);
  assert.equal(status.totalBytes, 190);
  assert.equal(status.downloadedBytes, 190, 'the meta.sqlite part of piece 0 is not counted');
  assert.equal(status.totalPieces, 3);
  assert.deepEqual(
    status.files.map((f) => [f.index, f.path, f.downloadedBytes, f.complete]),
    [
      [1, 'movie.mp4', 180, true],
      [2, 'movie.en.srt', 10, true],
    ],
  );
});

test('explicit fileIndexes restrict the pieces to download', async () => {
  const calls = [];
  const manager = managerFor(multiFileTorrent(), {
    downloadTorrentFn: async (t, p, options) => calls.push(options),
  });
  const id = await manager.startDownload({
    torrentBytes: Buffer.from('x'),
    outputDir: '/tmp/out',
    fileIndexes: [0],
  });
  await waitFor(() => manager.getStatus(id).status !== 'downloading');

  assert.deepEqual(calls[0].fileIndexes, [0]);
  assert.equal(manager.getStatus(id).totalPieces, 1);
  assert.equal(manager.getStatus(id).totalBytes, 50);
});

test('an out-of-range fileIndex or a changed info-hash fails the job without downloading', async () => {
  const calls = [];
  const manager = managerFor(multiFileTorrent(), {
    downloadTorrentFn: async (t, p, options) => calls.push(options),
  });

  const outOfRange = await manager.startDownload({
    torrentBytes: Buffer.from('x'),
    outputDir: '/o',
    fileIndexes: [3],
  });
  const changed = await manager.startDownload({
    torrentBytes: Buffer.from('x'),
    outputDir: '/o',
    expectedInfoHash: 'ff'.repeat(20),
  });
  await waitFor(() =>
    [outOfRange, changed].every((id) => manager.getStatus(id).status !== 'downloading'),
  );

  assert.equal(manager.getStatus(outOfRange).status, 'failed');
  assert.match(manager.getStatus(outOfRange).error, /out of range/);
  assert.equal(manager.getStatus(changed).status, 'failed');
  assert.match(manager.getStatus(changed).error, /changed since inspection/);
  assert.equal(calls.length, 0);
});

test('startDownload rejects a malformed fileIndexes or expectedInfoHash before creating a job', async () => {
  const manager = managerFor(multiFileTorrent());

  await assert.rejects(
    manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/o', fileIndexes: [] }),
    DownloadManagerError,
  );
  await assert.rejects(
    manager.startDownload({
      torrentBytes: Buffer.from('x'),
      outputDir: '/o',
      expectedInfoHash: 'nope',
    }),
    DownloadManagerError,
  );
});

test('inspectTorrent returns the file list, and a torrent URL that cannot be fetched is a TorrentFetchError', async () => {
  const manager = managerFor(multiFileTorrent(), {
    fetchImpl: async () => ({ ok: false, status: 503 }),
  });

  const inspection = await manager.inspectTorrent({ torrentBytes: Buffer.from('x') });
  assert.equal(inspection.mainVideoIndex, 1);
  assert.deepEqual(
    inspection.files.map((f) => f.suggested),
    [false, true, true],
  );

  await assert.rejects(
    manager.inspectTorrent({ torrentUrl: 'http://example.test/x.torrent' }),
    TorrentFetchError,
  );
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
