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

  await waitForEnd(manager, id);
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
    recheckPiecesFn: async () => new Set(),
    ...overrides,
  });
}

test('without fileIndexes, the suggested files are downloaded and progress is counted per file', async () => {
  const calls = [];
  const manager = managerFor(multiFileTorrent(), {
    downloadTorrentFn: completingDownload(calls, [0, 1, 2]),
  });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/tmp/out' });
  await waitForEnd(manager, id);

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
  await waitForEnd(manager, id);

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
  await waitForEnd(manager, outOfRange);
  await waitForEnd(manager, changed);

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

test('pieces already valid on disk are counted, skipped, and the job reports checking meanwhile', async () => {
  const calls = [];
  const recheckDone = deferred();
  const manager = managerFor(multiFileTorrent(), {
    recheckPiecesFn: async (torrent, { onPiece }) => {
      await recheckDone.promise;
      onPiece({ pieceIndex: 0, valid: true });

      return new Set([0]);
    },
    downloadTorrentFn: async (t, p, options) => {
      calls.push(options);
      options.onSourcesChange({ active: 2, dropped: 1 });
      options.onProgress({ pieceIndex: 1 });
      options.onProgress({ pieceIndex: 2 });
    },
  });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/o' });
  await new Promise((resolve) => setTimeout(resolve, 10));
  assert.equal(manager.getStatus(id).status, 'checking');

  recheckDone.resolve();
  await waitForEnd(manager, id);
  const status = manager.getStatus(id);
  assert.equal(status.status, 'completed', status.error ?? '');
  assert.deepEqual([...calls[0].skipPieces], [0]);
  assert.equal(status.piecesCompleted, 3);
  assert.equal(status.downloadedBytes, 190);
  assert.deepEqual(status.sources, { active: 2, dropped: 1 });
});

test('when every piece is already on disk, the job completes without announcing', async () => {
  let announced = false;
  const manager = managerFor(multiFileTorrent(), {
    announceFn: async () => {
      announced = true;

      return { peers: [] };
    },
    recheckPiecesFn: async (torrent, { onPiece }) => {
      [0, 1, 2].forEach((pieceIndex) => onPiece({ pieceIndex, valid: true }));

      return new Set([0, 1, 2]);
    },
  });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/o' });
  await waitForEnd(manager, id);

  assert.equal(manager.getStatus(id).status, 'completed');
  assert.equal(manager.getStatus(id).downloadedBytes, 190);
  assert.equal(announced, false);
});

test('refreshSources re-announces to the trackers and returns the new peers', async () => {
  const announces = [];
  let refreshed;
  const manager = managerFor(multiFileTorrent(), {
    announceFn: async (urls, params) => {
      announces.push(params);

      return { peers: [{ ip: '10.0.0.' + announces.length, port: 6881 }] };
    },
    downloadTorrentFn: async (t, p, options) => {
      refreshed = await options.refreshSources();
    },
  });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/o' });
  await waitForEnd(manager, id);

  assert.deepEqual(refreshed, [{ ip: '10.0.0.2', port: 6881 }]);
  assert.equal(announces[0].event, 'started');
  assert.equal(announces[1].event, undefined);
});

async function waitForEnd(manager, id) {
  await waitFor(() => ['completed', 'failed', 'cancelled'].includes(manager.getStatus(id).status));
}

async function waitFor(predicate, { timeoutMs = 2000, intervalMs = 5 } = {}) {
  const start = Date.now();

  while (!predicate()) {
    if (Date.now() - start > timeoutMs) {
      throw new Error('waitFor timed out');
    }

    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }
}

test('fetching the .torrent is retried after a network error, never after an HTTP error', async () => {
  let calls = 0;
  const flaky = managerFor(multiFileTorrent(), {
    torrentFetchRetryDelayMs: 0,
    fetchImpl: async () => {
      calls += 1;

      if (calls < 3) {
        throw new TypeError('fetch failed');
      }

      return new Response(Buffer.from('x'));
    },
  });
  const inspection = await flaky.inspectTorrent({ torrentUrl: 'http://example.test/x.torrent' });
  assert.equal(inspection.mainVideoIndex, 1);
  assert.equal(calls, 3);

  let httpCalls = 0;
  const refused = managerFor(multiFileTorrent(), {
    torrentFetchRetryDelayMs: 0,
    fetchImpl: async () => {
      httpCalls += 1;

      return new Response(null, { status: 404 });
    },
  });
  await assert.rejects(
    refused.inspectTorrent({ torrentUrl: 'http://example.test/x.torrent' }),
    TorrentFetchError,
  );
  assert.equal(httpCalls, 1);
});

// Stands in for downloadTorrent: writes movie.mp4, reports its last piece as unverified.
function downloadWithUnverifiedLastPiece(content) {
  return async (torrent, peers, options) => {
    const { writeFile } = await import('node:fs/promises');
    await writeFile(`${options.outputDir}/movie.mp4`, content);
    assert.equal(options.acceptUnverifiedPiece(2), true);
    options.onProgress({ completed: 1, total: 3, pieceIndex: 0 });
    options.onProgress({ completed: 2, total: 3, pieceIndex: 1 });
    options.onProgress({ completed: 3, total: 3, pieceIndex: 2, unverified: true });

    return { unverifiedPieces: [2] };
  };
}

test('an unverified piece completes the job only if the whole file matches archive.org', async () => {
  const { mkdtemp } = await import('node:fs/promises');
  const { tmpdir } = await import('node:os');
  const { createHash } = await import('node:crypto');
  const content = Buffer.alloc(240, 5);
  const sha1 = createHash('sha1').update(content).digest('hex');
  const torrent = { ...fakeTorrent({ urlList: ['https://archive.org/download/'] }), name: 'item' };
  const run = async (published) => {
    const outputDir = await mkdtemp(`${tmpdir()}/unverified-`);
    const asked = [];
    const manager = managerFor(torrent, {
      downloadTorrentFn: downloadWithUnverifiedLastPiece(content),
      fetchArchiveFileHashesFn: async (item) => {
        asked.push(item);

        return new Map([['movie.mp4', published]]);
      },
    });
    const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir });
    await waitForEnd(manager, id);

    return { status: manager.getStatus(id), asked };
  };

  const good = await run(sha1);
  assert.equal(good.status.status, 'completed');
  assert.deepEqual(good.asked, ['item']);
  assert.deepEqual(good.status.files[0].availableRanges, [[0, 240]]);

  const bad = await run('00'.repeat(20));
  assert.equal(bad.status.status, 'failed');
  assert.match(bad.status.error, /does not match the SHA-1 archive.org publishes/);
});
