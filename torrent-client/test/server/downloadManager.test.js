import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createDownloadManager, DownloadManagerError } from '../../src/server/downloadManager.js';
import { CancelledError } from '../../src/cancelledError.js';
import { SwarmDownloadError } from '../../src/swarm/downloadTorrent.js';

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

test('startDownload requires either torrentBytes or torrentUrl', async () => {
  const manager = createDownloadManager({});
  await assert.rejects(
    () => manager.startDownload({ outputDir: '/tmp/x' }),
    DownloadManagerError,
  );
});

test('startDownload requires an outputDir', async () => {
  const manager = createDownloadManager({});
  await assert.rejects(
    () => manager.startDownload({ torrentBytes: Buffer.from('x') }),
    DownloadManagerError,
  );
});

test('getStatus returns null for an unknown id', () => {
  const manager = createDownloadManager({});
  assert.equal(manager.getStatus('does-not-exist'), null);
});

test('a successful download transitions from downloading to completed with full progress', async () => {
  const torrent = fakeTorrent();
  const parseTorrentFileFn = () => torrent;
  const announceFn = async () => ({ peers: [{ ip: '127.0.0.1', port: 1 }] });
  let capturedOnProgress;
  const downloadTorrentFn = async (t, peers, options) => {
    capturedOnProgress = options.onProgress;
    options.onProgress({ completed: 1, total: 3, pieceIndex: 0 });
    options.onProgress({ completed: 2, total: 3, pieceIndex: 1 });
    options.onProgress({ completed: 3, total: 3, pieceIndex: 2 });
    return { outputDir: options.outputDir, piecesDownloaded: 3, files: ['movie.mp4'] };
  };

  const manager = createDownloadManager({ parseTorrentFileFn, announceFn, downloadTorrentFn });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('irrelevant'), outputDir: '/tmp/out' });

  assert.equal(typeof id, 'string');
  const initial = manager.getStatus(id);
  assert.equal(initial.status, 'downloading');
  assert.equal(initial.totalPieces, 3);

  await waitFor(() => manager.getStatus(id).status !== 'downloading');

  const finalStatus = manager.getStatus(id);
  assert.equal(finalStatus.status, 'completed');
  assert.equal(finalStatus.piecesCompleted, 3);
  assert.equal(finalStatus.downloadedBytes, finalStatus.totalBytes);
  assert.equal(finalStatus.error, null);
  assert.ok(capturedOnProgress);
});

test('a torrent fetch/parse failure moves the job to failed with an error message', async () => {
  const parseTorrentFileFn = () => {
    throw new Error('malformed torrent');
  };
  const manager = createDownloadManager({ parseTorrentFileFn });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('garbage'), outputDir: '/tmp/out' });

  await waitFor(() => manager.getStatus(id).status !== 'downloading');
  const status = manager.getStatus(id);
  assert.equal(status.status, 'failed');
  assert.match(status.error, /malformed torrent/);
});

test('a tracker announce with no peers fails the job with a clear error', async () => {
  const torrent = fakeTorrent();
  const parseTorrentFileFn = () => torrent;
  const announceFn = async () => ({ peers: [] });
  const manager = createDownloadManager({ parseTorrentFileFn, announceFn });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/tmp/out' });

  await waitFor(() => manager.getStatus(id).status !== 'downloading');
  const status = manager.getStatus(id);
  assert.equal(status.status, 'failed');
  assert.match(status.error, /peer/i);
});

test('passes torrent.urlList through to downloadTorrentFn as webSeedUrls', async () => {
  const torrent = fakeTorrent({ urlList: ['https://mirror.example/download/'] });
  const parseTorrentFileFn = () => torrent;
  const announceFn = async () => ({ peers: [{ ip: '127.0.0.1', port: 1 }] });
  let captured;
  const downloadTorrentFn = async (t, peers, options) => {
    captured = options.webSeedUrls;
    return { outputDir: options.outputDir, piecesDownloaded: 3, files: ['movie.mp4'] };
  };
  const manager = createDownloadManager({ parseTorrentFileFn, announceFn, downloadTorrentFn });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/tmp/out' });

  await waitFor(() => manager.getStatus(id).status !== 'downloading');
  assert.deepEqual(captured, ['https://mirror.example/download/']);
});

test('succeeds via web-seed alone when the tracker returns zero peers but the torrent has url-list', async () => {
  const torrent = fakeTorrent({ urlList: ['https://mirror.example/download/'] });
  const parseTorrentFileFn = () => torrent;
  const announceFn = async () => ({ peers: [] });
  const downloadTorrentFn = async (t, peers, options) => {
    assert.deepEqual(peers, []);
    assert.deepEqual(options.webSeedUrls, ['https://mirror.example/download/']);
    return { outputDir: options.outputDir, piecesDownloaded: 3, files: ['movie.mp4'] };
  };
  const manager = createDownloadManager({ parseTorrentFileFn, announceFn, downloadTorrentFn });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/tmp/out' });

  await waitFor(() => manager.getStatus(id).status !== 'downloading');
  assert.equal(manager.getStatus(id).status, 'completed');
});

test('a swarm download failure moves the job to failed', async () => {
  const torrent = fakeTorrent();
  const parseTorrentFileFn = () => torrent;
  const announceFn = async () => ({ peers: [{ ip: '127.0.0.1', port: 1 }] });
  const downloadTorrentFn = async () => {
    throw new SwarmDownloadError('every peer failed');
  };
  const manager = createDownloadManager({ parseTorrentFileFn, announceFn, downloadTorrentFn });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/tmp/out' });

  await waitFor(() => manager.getStatus(id).status !== 'downloading');
  const status = manager.getStatus(id);
  assert.equal(status.status, 'failed');
  assert.match(status.error, /every peer failed/);
});

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

test('cancelDownload returns null for an unknown id', () => {
  const manager = createDownloadManager({});
  assert.equal(manager.cancelDownload('does-not-exist'), null);
});

test('cancelDownload on an already-completed job is a harmless no-op', async () => {
  const torrent = fakeTorrent();
  const parseTorrentFileFn = () => torrent;
  const announceFn = async () => ({ peers: [{ ip: '127.0.0.1', port: 1 }] });
  const downloadTorrentFn = async (t, peers, options) => {
    options.onProgress({ completed: 3, total: 3, pieceIndex: 2 });
    return { outputDir: options.outputDir, piecesDownloaded: 3, files: ['movie.mp4'] };
  };
  const manager = createDownloadManager({ parseTorrentFileFn, announceFn, downloadTorrentFn });
  const id = await manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/tmp/out' });
  await waitFor(() => manager.getStatus(id).status !== 'downloading');

  const result = manager.cancelDownload(id);
  assert.equal(result.status, 'completed');
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
