import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import {
  createDownloadManager,
  DownloadManagerError,
  TorrentFetchError,
} from '../../src/server/downloadManager.js';
import { STATUS } from '../../src/transmission/transmissionClient.js';

const HASH = '01'.repeat(20);

// Pieces of 100 bytes over three files: meta.xml [0,50), movie.mp4 [50,300), subs.srt [300,340).
function multiFileTorrent() {
  return {
    infoHash: HASH,
    name: 'some_item',
    pieceLength: 100,
    pieces: ['h0', 'h1', 'h2', 'h3'],
    totalLength: 340,
    files: [
      { path: 'some_item_meta.xml', length: 50 },
      { path: 'Movie (1931)/movie.mp4', length: 250 },
      { path: 'Movie (1931)/subs.srt', length: 40 },
    ],
  };
}

function bitfield(states) {
  const bytes = Buffer.alloc(Math.ceil(states.length / 8));
  states.forEach((done, piece) => {
    if (done) {
      bytes[piece >> 3] |= 0x80 >> (piece & 7);
    }
  });

  return bytes.toString('base64');
}

// Transmission in memory: every getTorrent() call once started verifies `piecesPerPoll` more
// pieces, in order. Files live under "<name>/", as Transmission stores a multi-file torrent.
function fakeTransmission({ existing = null, error = 0, piecesPerPoll = 1 } = {}) {
  const calls = [];
  const torrents = new Map();
  const fake = {
    calls,
    torrents,
    async getTorrent(hash) {
      const t = torrents.get(hash);

      if (!t) {
        return null;
      }

      if (t.started) {
        for (let n = 0; n < piecesPerPoll; n += 1) {
          const next = t.done.indexOf(false);

          if (next !== -1) {
            t.done[next] = true;
          }
        }
      }

      return {
        hashString: hash,
        status: t.started ? STATUS.downloading : STATUS.stopped,
        error,
        errorString: error ? 'No space left on device' : '',
        downloadDir: t.downloadDir,
        pieces: bitfield(t.done),
        peersConnected: 0,
        webseedsSendingToUs: 0,
      };
    },
    async addTorrent({ downloadDir }) {
      calls.push(['add', downloadDir]);
      torrents.set(HASH, { downloadDir, started: false, done: [false, false, false, false] });

      return HASH;
    },
    async files() {
      return multiFileTorrent().files.map((f) => ({ name: `some_item/${f.path}` }));
    },
    async selectFiles(hash, selection) {
      calls.push(['select', selection]);
    },
    async start() {
      calls.push(['start']);
      torrents.get(HASH).started = true;
    },
    async remove(hash) {
      calls.push(['remove']);
      torrents.delete(hash);
    },
  };

  if (existing) {
    torrents.set(HASH, existing);
  }

  return fake;
}

function managerFor(torrent, transmission, options = {}) {
  return createDownloadManager({
    transmission,
    parseTorrentFileFn: () => torrent,
    pollIntervalMs: 1,
    ...options,
  });
}

const start = (manager, extra = {}) =>
  manager.startDownload({ torrentBytes: Buffer.from('x'), outputDir: '/movies/7', ...extra });

const settled = (manager, id) =>
  waitFor(() => ['completed', 'failed', 'cancelled'].includes(manager.getStatus(id).status));

test('the suggested files are downloaded, then linked flat into outputDir', async () => {
  const outputDir = await mkdtemp(join(tmpdir(), 'movies-'));
  const nested = join(outputDir, 'some_item', 'Movie (1931)');
  await mkdir(nested, { recursive: true });
  await writeFile(join(nested, 'movie.mp4'), 'm'.repeat(250));
  await writeFile(join(nested, 'subs.srt'), 's'.repeat(40));
  const transmission = fakeTransmission();
  const manager = managerFor(multiFileTorrent(), transmission);

  const id = await start(manager, { outputDir });
  await settled(manager, id);
  const status = manager.getStatus(id);

  assert.equal(status.status, 'completed');
  assert.deepEqual(transmission.calls, [
    ['add', outputDir],
    ['select', { wanted: [1, 2], unwanted: [0] }],
    ['start'],
  ]);
  assert.equal(status.totalPieces, 4); // piece 0 is shared with meta.xml
  assert.equal(status.piecesCompleted, 4);
  assert.equal(status.totalBytes, 290);
  assert.equal(status.downloadedBytes, 290);
  assert.deepEqual(
    status.files.map((f) => [f.index, f.fileName, f.complete, f.contiguousBytesFromStart]),
    [
      [1, 'movie.mp4', true, 250],
      [2, 'subs.srt', true, 40],
    ],
  );
  assert.equal(status.pieces, Buffer.from([0xf0]).toString('base64'));
  assert.equal(status.layout, undefined);
  assert.equal(await readFile(join(outputDir, 'movie.mp4'), 'utf8'), 'm'.repeat(250));
  assert.equal(await readFile(join(outputDir, 'subs.srt'), 'utf8'), 's'.repeat(40));
  assert.equal(transmission.torrents.has(HASH), true, 'kept in Transmission once completed');
});

test('progress is reported per file while Transmission downloads in order', async () => {
  const transmission = fakeTransmission();
  const manager = managerFor(multiFileTorrent(), transmission, { pollIntervalMs: 30 });

  const id = await start(manager, { fileIndexes: [1] });
  await waitFor(() => manager.getStatus(id).piecesCompleted === 2);
  const [movie] = manager.getStatus(id).files;

  // Pieces 0 and 1 cover bytes [0,200): the first 150 bytes of movie.mp4.
  assert.equal(manager.getStatus(id).status, 'downloading');
  assert.equal(manager.getStatus(id).totalPieces, 3);
  assert.equal(movie.downloadedBytes, 150);
  assert.equal(movie.contiguousBytesFromStart, 150);
  assert.deepEqual(movie.availableRanges, [[0, 150]]);
  assert.deepEqual(transmission.calls[1], ['select', { wanted: [1], unwanted: [0, 2] }]);
  assert.equal(manager.getStreamSource(id, 1).file.diskPath, 'some_item/Movie (1931)/movie.mp4');

  manager.cancelDownload(id);
  await settled(manager, id);
});

test('cancelDownload stops the job and Transmission forgets the torrent, files stay', async () => {
  const transmission = fakeTransmission({ piecesPerPoll: 0 });
  const manager = managerFor(multiFileTorrent(), transmission);

  const id = await start(manager);
  await waitFor(() => transmission.calls.some(([name]) => name === 'start'));
  manager.cancelDownload(id);
  await settled(manager, id);

  assert.equal(manager.getStatus(id).status, 'cancelled');
  assert.deepEqual(transmission.calls.at(-1), ['remove']);
});

test('a torrent Transmission already has in the same folder is resumed, not added again', async () => {
  const outputDir = await mkdtemp(join(tmpdir(), 'movies-'));
  await mkdir(join(outputDir, 'some_item'));
  await writeFile(join(outputDir, 'some_item', 'some_item_meta.xml'), 'x'.repeat(50));
  const transmission = fakeTransmission({
    existing: { downloadDir: `${outputDir}/`, started: false, done: [true, true, true, true] },
  });
  const manager = managerFor(multiFileTorrent(), transmission);

  const id = await start(manager, { outputDir, fileIndexes: [0] });
  await settled(manager, id);

  assert.equal(manager.getStatus(id).status, 'completed');
  assert.equal(
    transmission.calls.some(([name]) => name === 'add'),
    false,
  );
});

test('the same torrent already downloading into another folder fails the job', async () => {
  const transmission = fakeTransmission({
    existing: { downloadDir: '/movies/3', started: true, done: [false] },
  });
  const manager = managerFor(multiFileTorrent(), transmission);

  const id = await start(manager);
  await settled(manager, id);

  assert.equal(manager.getStatus(id).status, 'failed');
  assert.match(manager.getStatus(id).error, /already downloads this torrent into \/movies\/3/);
  assert.equal(transmission.torrents.has(HASH), true, 'the other download is left alone');
});

test('no new piece for stallTimeoutMs, or a local error in Transmission, fails the job', async () => {
  const stalled = fakeTransmission({ piecesPerPoll: 0 });
  const broken = fakeTransmission({ error: 3 });
  const a = managerFor(multiFileTorrent(), stalled, { stallTimeoutMs: 20 });
  const b = managerFor(multiFileTorrent(), broken);

  const stalledId = await start(a);
  const brokenId = await start(b);
  await settled(a, stalledId);
  await settled(b, brokenId);

  assert.equal(a.getStatus(stalledId).status, 'failed');
  assert.match(a.getStatus(stalledId).error, /No piece completed .*0 peer\(s\)/);
  assert.deepEqual(stalled.calls.at(-1), ['remove']);
  assert.equal(b.getStatus(brokenId).status, 'failed');
  assert.match(b.getStatus(brokenId).error, /No space left on device/);
});

test('an out-of-range fileIndex or a changed info-hash fails the job before Transmission', async () => {
  const transmission = fakeTransmission();
  const manager = managerFor(multiFileTorrent(), transmission);

  const outOfRange = await start(manager, { fileIndexes: [3] });
  const changed = await start(manager, { expectedInfoHash: 'ff'.repeat(20) });
  await settled(manager, outOfRange);
  await settled(manager, changed);

  assert.equal(manager.getStatus(outOfRange).status, 'failed');
  assert.match(manager.getStatus(outOfRange).error, /out of range/);
  assert.equal(manager.getStatus(changed).status, 'failed');
  assert.match(manager.getStatus(changed).error, /changed since inspection/);
  assert.equal(transmission.calls.length, 0);
});

test('startDownload rejects a malformed fileIndexes or expectedInfoHash before creating a job', async () => {
  const manager = managerFor(multiFileTorrent(), fakeTransmission());

  await assert.rejects(start(manager, { fileIndexes: [] }), DownloadManagerError);
  await assert.rejects(start(manager, { expectedInfoHash: 'nope' }), DownloadManagerError);
  await assert.rejects(start(manager, { outputDir: '' }), DownloadManagerError);
});

test('inspectTorrent returns the file list with flat names, and an unreachable URL is a TorrentFetchError', async () => {
  const manager = managerFor(multiFileTorrent(), fakeTransmission(), {
    fetchImpl: async () => ({ ok: false, status: 503 }),
  });

  const inspection = await manager.inspectTorrent({ torrentBytes: Buffer.from('x') });
  assert.equal(inspection.mainVideoIndex, 1);
  assert.deepEqual(
    inspection.files.map((f) => [f.fileName, f.suggested]),
    [
      ['some_item_meta.xml', false],
      ['movie.mp4', true],
      ['subs.srt', true],
    ],
  );

  await assert.rejects(
    manager.inspectTorrent({ torrentUrl: 'http://example.test/x.torrent' }),
    TorrentFetchError,
  );
});

async function waitFor(predicate, { timeoutMs = 2000, intervalMs = 5 } = {}) {
  const startedAt = Date.now();

  while (!predicate()) {
    if (Date.now() - startedAt > timeoutMs) {
      throw new Error('waitFor timed out');
    }

    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }
}
