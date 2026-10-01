import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer as createTcpServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { buildHandshake } from '../../src/peer/handshake.js';
import { encodeMessage, extractMessages, MESSAGE_ID } from '../../src/peer/messages.js';
import { createDownloadManager } from '../../src/server/downloadManager.js';
import { createServer } from '../../src/server/httpServer.js';
import { parseRange } from '../../src/stream/streamFile.js';
import { downloadTorrent } from '../../src/swarm/downloadTorrent.js';

const INFO_HASH = Buffer.from('0102030405060708090a0b0c0d0e0f1011121314', 'hex');
const PIECE_LENGTH = 16384;
// meta.sqlite [0,5000) shares piece 0 with movie.mp4 [5000,5000+MOVIE_LENGTH), whose end
// shares the last piece with subs.srt: the same layout as the prototype's overlap cases.
const MOVIE_LENGTH = PIECE_LENGTH * 7 + 3000;
const FILES = [
  { path: 'meta.sqlite', length: 5000 },
  { path: 'movie.mp4', length: MOVIE_LENGTH },
  { path: 'subs.srt', length: 4000 },
];
const STREAM = Buffer.alloc(FILES.reduce((sum, f) => sum + f.length, 0));

for (let i = 0; i < STREAM.length; i += 1) {
  STREAM[i] = (i * 7) % 251;
}

// the movie starts with an MP4 signature: size, then "ftyp"
STREAM.write('\x00\x00\x00\x20ftypisom', 5000, 'latin1');
const MOVIE = STREAM.subarray(5000, 5000 + MOVIE_LENGTH);
const PIECES = [];

for (let offset = 0; offset < STREAM.length; offset += PIECE_LENGTH) {
  PIECES.push(STREAM.subarray(offset, offset + PIECE_LENGTH));
}

const TORRENT = {
  name: 'item',
  announce: 'http://tracker.test/announce',
  urlList: [],
  infoHash: INFO_HASH.toString('hex'),
  pieceLength: PIECE_LENGTH,
  pieces: PIECES.map((p) => createHash('sha1').update(p).digest('hex')),
  totalLength: STREAM.length,
  files: FILES,
};
const LAST_PIECE = PIECES.length - 1;

// A peer that answers each request after `delayMs`, never answers `deadPieces`, and records
// the order in which pieces were first requested.
function startSlowPeer({ delayMs = 60, deadPieces = new Set() } = {}) {
  const requested = [];
  const server = createTcpServer((socket) => {
    let buffer = Buffer.alloc(0);
    let handshakeDone = false;
    socket.on('error', () => {});
    socket.on('data', (chunk) => {
      buffer = Buffer.concat([buffer, chunk]);

      if (!handshakeDone) {
        if (buffer.length < 68) {
          return;
        }

        handshakeDone = true;
        buffer = buffer.subarray(68);
        socket.write(buildHandshake(INFO_HASH, Buffer.alloc(20, 9)));
        socket.write(encodeMessage(MESSAGE_ID.UNCHOKE));
      }

      const { messages, remaining } = extractMessages(buffer);
      buffer = remaining;

      for (const message of messages) {
        if (message.id !== MESSAGE_ID.REQUEST) {
          continue;
        }

        const index = message.payload.readUInt32BE(0);
        const begin = message.payload.readUInt32BE(4);
        const length = message.payload.readUInt32BE(8);

        if (begin === 0) {
          requested.push(index);
        }

        if (deadPieces.has(index)) {
          continue;
        }

        setTimeout(() => {
          const header = Buffer.alloc(8);
          header.writeUInt32BE(index, 0);
          header.writeUInt32BE(begin, 4);
          socket.write(
            encodeMessage(
              MESSAGE_ID.PIECE,
              Buffer.concat([header, PIECES[index].subarray(begin, begin + length)]),
            ),
          );
        }, delayMs);
      }
    });
  });

  return new Promise((resolve) =>
    server.listen(0, '127.0.0.1', () => resolve({ server, requested })),
  );
}

async function withStack(peerOptions, fn, { stallTimeoutMs = 3000 } = {}) {
  const peer = await startSlowPeer(peerOptions);
  const outputDir = await mkdtemp(join(tmpdir(), 'torrent-stream-test-'));
  const manager = createDownloadManager({
    parseTorrentFileFn: () => TORRENT,
    announceFn: async () => ({ peers: [{ ip: '127.0.0.1', port: peer.server.address().port }] }),
    // one piece at a time, so the order of requests shows the priorities
    downloadTorrentFn: (torrent, peers, options) =>
      downloadTorrent(torrent, peers, {
        ...options,
        concurrency: 1,
        pieceTimeoutMs: 1000,
        backoffBaseMs: 10,
        stallTimeoutMs: 5000,
      }),
  });
  const http = createServer({ manager, streamOptions: { readaheadBytes: 0, stallTimeoutMs } });
  await new Promise((resolve) => http.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${http.address().port}`;
  const start = (body = {}) =>
    fetch(`${base}/downloads`, {
      method: 'POST',
      body: JSON.stringify({ torrentBase64: 'eA==', outputDir, fileIndexes: [1], ...body }),
    })
      .then((r) => r.json())
      .then((r) => r.id);

  try {
    return await fn({ base, start, manager, requested: peer.requested });
  } finally {
    await new Promise((resolve) => http.close(resolve));
    http.closeAllConnections();
    peer.server.close();
    await rm(outputDir, { recursive: true, force: true });
  }
}

async function waitUntil(predicate, timeoutMs = 8000) {
  const startedAt = Date.now();

  while (!(await predicate())) {
    if (Date.now() - startedAt > timeoutMs) {
      throw new Error('waitUntil timed out');
    }

    await new Promise((resolve) => setTimeout(resolve, 20));
  }
}

test('parseRange: the three byte-range forms, the whole file, and unsatisfiable ranges', () => {
  assert.deepEqual(parseRange('bytes=10-19', 100), { start: 10, end: 19 });
  assert.deepEqual(parseRange('bytes=90-', 100), { start: 90, end: 99 });
  assert.deepEqual(parseRange('bytes=-10', 100), { start: 90, end: 99 });
  assert.deepEqual(parseRange('bytes=-500', 100), { start: 0, end: 99 });
  assert.deepEqual(parseRange('bytes=10-500', 100), { start: 10, end: 99 });
  assert.equal(parseRange(undefined, 100), null);
  assert.equal(parseRange('bytes=0-1,5-6', 100), null, 'multiple ranges: serve the whole file');
  assert.deepEqual(parseRange('bytes=100-', 100), { unsatisfiable: true });
  assert.deepEqual(parseRange('bytes=-0', 100), { unsatisfiable: true });
});

test('the first and last pieces of the main video are fetched before the rest', async () => {
  await withStack({}, async ({ base, start, requested }) => {
    const id = await start();
    await waitUntil(
      async () =>
        (await fetch(`${base}/downloads/${id}`).then((r) => r.json())).status === 'completed',
    );

    assert.deepEqual(requested.slice(0, 2), [0, LAST_PIECE]);
    assert.deepEqual(requested.slice(2), [1, 2, 3, 4, 5, 6]);
  });
});

test('a range on the end of the movie (where a moov would be) jumps the queue and returns the exact bytes', async () => {
  await withStack({}, async ({ base, start, requested }) => {
    const id = await start();
    // let the boosted pieces go first, then ask for bytes in the middle of the queue
    await waitUntil(() => requested.length >= 3);
    const res = await fetch(`${base}/downloads/${id}/files/1`, {
      headers: {
        Range: `bytes=${MOVIE_LENGTH - PIECE_LENGTH * 3}-${MOVIE_LENGTH - PIECE_LENGTH * 2 - 1}`,
      },
    });

    assert.equal(res.status, 206);
    assert.equal(res.headers.get('content-type'), 'video/mp4');
    assert.equal(
      res.headers.get('content-range'),
      `bytes ${MOVIE_LENGTH - PIECE_LENGTH * 3}-${MOVIE_LENGTH - PIECE_LENGTH * 2 - 1}/${MOVIE_LENGTH}`,
    );
    const body = Buffer.from(await res.arrayBuffer());
    assert.ok(
      body.equals(MOVIE.subarray(MOVIE_LENGTH - PIECE_LENGTH * 3, MOVIE_LENGTH - PIECE_LENGTH * 2)),
    );
    // the pieces of that range were fetched before pieces with a lower index
    const rangePieces = [5, 6];
    const firstRangeRequest = Math.min(...rangePieces.map((p) => requested.indexOf(p)));
    assert.ok(firstRangeRequest < requested.indexOf(4), `request order: ${requested}`);
  });
});

test("a whole-file GET streams the movie while it downloads, without the other files' bytes", async () => {
  await withStack({}, async ({ base, start }) => {
    const id = await start();
    const res = await fetch(`${base}/downloads/${id}/files/1`);

    assert.equal(res.status, 200);
    assert.ok(Buffer.from(await res.arrayBuffer()).equals(MOVIE));
  });
});

test('status exposes availability, the bitfield and the container sniffed from the first bytes', async () => {
  await withStack({ delayMs: 5 }, async ({ base, start }) => {
    const id = await start();
    let status;
    await waitUntil(async () => {
      status = await fetch(`${base}/downloads/${id}`).then((r) => r.json());

      return status.status === 'completed' && status.files[0].detectedContainer !== null;
    });

    const [movie] = status.files;
    assert.equal(movie.detectedContainer, 'mp4');
    assert.equal(movie.contiguousBytesFromStart, MOVIE_LENGTH);
    assert.deepEqual(movie.availableRanges, [[0, MOVIE_LENGTH]]);
    assert.equal(Buffer.from(status.pieces, 'base64')[0], 0xff, 'pieces 0-7 all verified');
  });
});

test('404 for an unknown job or an unselected file, 416 outside the file', async () => {
  await withStack({}, async ({ base, start }) => {
    const id = await start();

    assert.equal((await fetch(`${base}/downloads/nope/files/1`)).status, 404);
    assert.equal(
      (await fetch(`${base}/downloads/${id}/files/0`)).status,
      404,
      'meta.sqlite not chosen',
    );
    const outside = await fetch(`${base}/downloads/${id}/files/1`, {
      headers: { Range: `bytes=${MOVIE_LENGTH}-` },
    });
    assert.equal(outside.status, 416);
    assert.equal(outside.headers.get('content-range'), `bytes */${MOVIE_LENGTH}`);
  });
});

test('a piece that does not arrive in time gives 503 + Retry-After, a cancelled job gives 410', async () => {
  await withStack(
    { deadPieces: new Set([LAST_PIECE]) },
    async ({ base, start }) => {
      const id = await start();
      const lastBytes = { headers: { Range: 'bytes=-100' } };

      const stalled = await fetch(`${base}/downloads/${id}/files/1`, lastBytes);
      assert.equal(stalled.status, 503);
      assert.equal(stalled.headers.get('retry-after'), '5');

      await fetch(`${base}/downloads/${id}`, { method: 'DELETE' });
      await waitUntil(
        async () =>
          (await fetch(`${base}/downloads/${id}`).then((r) => r.json())).status === 'cancelled',
      );

      const gone = await fetch(`${base}/downloads/${id}/files/1`, lastBytes);
      assert.equal(gone.status, 410);
      assert.deepEqual(await gone.json(), { status: 'cancelled', error: null });

      const head = await fetch(`${base}/downloads/${id}/files/1`, {
        headers: { Range: 'bytes=0-99' },
      });
      assert.equal(head.status, 206, 'bytes already on disk are still served');
    },
    { stallTimeoutMs: 400 },
  );
});

test('streamFile finishes and closes the file when the reader goes away mid-response', async () => {
  const { createServer: createHttpServer, get } = await import('node:http');
  const { writeFile } = await import('node:fs/promises');
  const { streamFile } = await import('../../src/stream/streamFile.js');
  const { createPieceAvailability } = await import('../../src/stream/pieceAvailability.js');
  const dir = await mkdtemp(join(tmpdir(), 'torrent-stream-close-'));
  const path = join(dir, 'big.mp4');
  const length = 32 * 1024 * 1024; // far more than the socket buffers: the server must wait for drain
  await writeFile(path, Buffer.alloc(length, 1));
  const availability = createPieceAvailability({
    pieceCount: 2,
    pieceLength: length,
    totalLength: length,
  });
  availability.mark(0);
  let finished;
  const server = createHttpServer((req, res) => {
    finished = streamFile(
      req,
      res,
      {
        file: { index: 0, path: 'big.mp4', length, torrentOffset: 0 },
        path,
        pieceLength: length,
        availability,
        endedState: () => null,
      },
      { readaheadBytes: 0, stallTimeoutMs: 1000 },
    );
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    await new Promise((resolve) => {
      const req = get(`http://127.0.0.1:${server.address().port}/`, (res) => {
        res.once('data', () => {
          res.pause(); // stop reading so the server blocks on backpressure, then hang up
          setTimeout(() => {
            req.destroy();
            resolve();
          }, 100);
        });
      });
      req.on('error', () => {});
    });

    const outcome = await Promise.race([
      finished.then(() => 'finished'),
      new Promise((resolve) => setTimeout(() => resolve('still hanging'), 2000)),
    ]);
    assert.equal(outcome, 'finished');
  } finally {
    server.close();
    await rm(dir, { recursive: true, force: true });
  }
});
