import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { access, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createServer as createHttpServer } from 'node:http';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { buildHandshake } from '../../src/peer/handshake.js';
import { encodeMessage, extractMessages, MESSAGE_ID } from '../../src/peer/messages.js';
import { downloadTorrent } from '../../src/swarm/downloadTorrent.js';

const INFO_HASH = Buffer.from('0102030405060708090a0b0c0d0e0f1011121314', 'hex');
const CLIENT_PEER_ID = Buffer.from('-HT0001-abcdefghijkl', 'ascii');
const PIECE_LENGTH = 16384;

function buildPieces(count, lastPieceLength = PIECE_LENGTH) {
  const pieces = [];

  for (let i = 0; i < count; i += 1) {
    const length = i === count - 1 ? lastPieceLength : PIECE_LENGTH;
    pieces.push(Buffer.alloc(length, i + 1)); // fill byte = piece index + 1, so each piece is distinguishable
  }

  return pieces;
}

// Single-file torrent: the whole concatenated stream is one file.
function torrentFor(pieces, { fileName = 'output.bin' } = {}) {
  const totalLength = pieces.reduce((sum, p) => sum + p.length, 0);

  return {
    name: 'test-torrent',
    infoHash: INFO_HASH.toString('hex'),
    pieceLength: PIECE_LENGTH,
    pieces: pieces.map((p) => createHash('sha1').update(p).digest('hex')),
    totalLength,
    files: [{ path: fileName, length: totalLength }],
  };
}

// A fake peer that serves real piece bytes for whichever piece index is
// requested, except those in `failPieceIndexes` (silently dropped --
// simulates a peer that can't serve that piece).
function startFakePeer(pieces, { failPieceIndexes = new Set() } = {}) {
  const server = createServer((socket) => {
    let buffer = Buffer.alloc(0);
    let handshakeDone = false;
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

        if (failPieceIndexes.has(index)) {
          continue;
        }

        const piece = pieces[index];
        const block = piece.subarray(begin, begin + length);
        const header = Buffer.alloc(8);
        header.writeUInt32BE(index, 0);
        header.writeUInt32BE(begin, 4);
        socket.write(encodeMessage(MESSAGE_ID.PIECE, Buffer.concat([header, block])));
      }
    });
  });

  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

// A fake BEP19 web-seed HTTP server: serves ranged GETs at
// <base>/<torrentName>/<filePath>, matching the archive.org convention
// downloadPieceFromWebSeed expects. `files` maps a file path to its full
// content buffer.
function startFakeWebSeed(torrentName, files, { pieceLength, failPieceIndexes = new Set() } = {}) {
  const server = createHttpServer((req, res) => {
    const prefix = `/${encodeURIComponent(torrentName)}/`;

    if (!req.url.startsWith(prefix)) {
      res.writeHead(404);
      res.end();

      return;
    }

    const filePath = decodeURIComponent(req.url.slice(prefix.length));

    if (!files[filePath]) {
      res.writeHead(404);
      res.end();

      return;
    }

    const content = files[filePath];
    const match = /bytes=(\d+)-(\d+)/.exec(req.headers.range ?? '');

    if (match && pieceLength) {
      const pieceIndex = Math.floor(Number(match[1]) / pieceLength);

      if (failPieceIndexes.has(pieceIndex)) {
        res.writeHead(404);
        res.end();

        return;
      }
    }

    if (!match) {
      res.writeHead(200, { 'Content-Length': content.length });
      res.end(content);

      return;
    }

    const start = Number(match[1]);
    const end = Number(match[2]);
    const slice = content.subarray(start, end + 1);
    res.writeHead(206, {
      'Content-Range': `bytes ${start}-${end}/${content.length}`,
      'Content-Length': slice.length,
    });
    res.end(slice);
  });

  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

async function withTempDir(fn) {
  const dir = await mkdtemp(join(tmpdir(), 'torrent-swarm-test-'));

  try {
    return await fn(dir);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}

test('creates zero-length files on disk even though no piece ever overlaps them', async () => {
  const pieces = buildPieces(2);
  const totalLength = pieces.reduce((sum, p) => sum + p.length, 0);
  const torrent = {
    ...torrentFor(pieces),
    files: [
      { path: 'empty-log.txt', length: 0 },
      { path: 'movie.mp4', length: totalLength },
      { path: 'empty-trigger.txt', length: 0 },
    ],
  };
  const server = await startFakePeer(pieces);

  try {
    await withTempDir(async (outputDir) => {
      const result = await downloadTorrent(
        torrent,
        [{ ip: '127.0.0.1', port: server.address().port }],
        {
          infoHash: INFO_HASH,
          peerId: CLIENT_PEER_ID,
          outputDir,
          concurrency: 2,
        },
      );
      assert.deepEqual(result.files, ['empty-log.txt', 'movie.mp4', 'empty-trigger.txt']);
      const empty1 = await readFile(join(outputDir, 'empty-log.txt'));
      const empty2 = await readFile(join(outputDir, 'empty-trigger.txt'));
      assert.equal(empty1.length, 0);
      assert.equal(empty2.length, 0);
      const movie = await readFile(join(outputDir, 'movie.mp4'));
      assert.ok(movie.equals(Buffer.concat(pieces)));
    });
  } finally {
    server.close();
  }
});

test('with fileIndexes, only the pieces covering the chosen file are fetched and only its bytes are written', async () => {
  const pieces = buildPieces(4);
  const stream = Buffer.concat(pieces);
  // meta.sqlite [0,10000) and movie.mp4 [10000,42768) share piece 0; movie.mp4 and extra.mp3
  // [42768,65536) share piece 2; piece 3 only holds extra.mp3.
  const torrent = {
    ...torrentFor(pieces),
    files: [
      { path: 'meta.sqlite', length: 10000 },
      { path: 'movie.mp4', length: 32768 },
      { path: 'extra.mp3', length: stream.length - 42768 },
    ],
  };
  // the peer never answers for piece 3: the download can only succeed if it is never asked.
  const server = await startFakePeer(pieces, { failPieceIndexes: new Set([3]) });

  try {
    await withTempDir(async (outputDir) => {
      const result = await downloadTorrent(
        torrent,
        [{ ip: '127.0.0.1', port: server.address().port }],
        {
          infoHash: INFO_HASH,
          peerId: CLIENT_PEER_ID,
          outputDir,
          concurrency: 2,
          pieceTimeoutMs: 2000,
          stallTimeoutMs: 3000,
          fileIndexes: [1],
        },
      );
      assert.equal(result.piecesDownloaded, 3);
      assert.deepEqual(result.files, ['movie.mp4']);
      const movie = await readFile(join(outputDir, 'movie.mp4'));
      assert.ok(movie.equals(stream.subarray(10000, 42768)));
      await assert.rejects(access(join(outputDir, 'meta.sqlite')), { code: 'ENOENT' });
      await assert.rejects(access(join(outputDir, 'extra.mp3')), { code: 'ENOENT' });
    });
  } finally {
    server.close();
  }
});

test('combines a real peer and a web-seed in the same download rather than picking one', async () => {
  const pieces = buildPieces(10);
  const torrent = torrentFor(pieces);
  const evenIndexes = pieces.map((_, i) => i).filter((i) => i % 2 === 0);
  const oddIndexes = pieces.map((_, i) => i).filter((i) => i % 2 !== 0);
  // the peer can only serve even-indexed pieces, the web-seed only odd --
  // the download only completes if both sources actually get used, not if
  // the client sticks to whichever one happens to answer first.
  const peer = await startFakePeer(pieces, { failPieceIndexes: new Set(oddIndexes) });
  const fullFile = Buffer.concat(pieces);
  const webSeed = await startFakeWebSeed(
    torrent.name,
    { 'output.bin': fullFile },
    {
      pieceLength: PIECE_LENGTH,
      failPieceIndexes: new Set(evenIndexes),
    },
  );
  const { port } = webSeed.address();

  try {
    await withTempDir(async (outputDir) => {
      const result = await downloadTorrent(
        torrent,
        [{ ip: '127.0.0.1', port: peer.address().port }],
        {
          infoHash: INFO_HASH,
          peerId: CLIENT_PEER_ID,
          outputDir,
          concurrency: 4,
          pieceTimeoutMs: 2000,
          backoffBaseMs: 10,
          webSeedUrls: [`http://127.0.0.1:${port}/`],
        },
      );
      assert.equal(result.piecesDownloaded, 10);
      const written = await readFile(join(outputDir, 'output.bin'));
      assert.ok(written.equals(fullFile));
    });
  } finally {
    peer.close();
    webSeed.close();
  }
});

test('a failed piece reports every distinct source failure, not only the last one', async () => {
  const pieces = buildPieces(1);
  const torrent = torrentFor(pieces);
  // one web-seed serves bytes of the right length but wrong content (hash mismatch, like an
  // archive.org _meta.xml regenerated after the torrent was made), the other is unreachable.
  const wrongContent = Buffer.alloc(pieces[0].length, 99);
  const webSeed = await startFakeWebSeed(torrent.name, { 'output.bin': wrongContent }, {});
  const { port } = webSeed.address();

  try {
    await withTempDir(async (outputDir) => {
      await assert.rejects(
        downloadTorrent(torrent, [], {
          infoHash: INFO_HASH,
          peerId: CLIENT_PEER_ID,
          outputDir,
          pieceTimeoutMs: 2000,
          backoffBaseMs: 10,
          stallTimeoutMs: 500,
          webSeedUrls: [`http://127.0.0.1:${port}/`, 'http://127.0.0.1:1/'],
        }),
        (err) => /hash mismatch/.test(err.message) && /Web-seed request failed/.test(err.message),
      );
    });
  } finally {
    webSeed.close();
  }
});

// A TCP port nothing listens on: connecting is refused straight away (ECONNREFUSED).
async function deadPeer() {
  const server = createServer();
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address();
  await new Promise((resolve) => server.close(resolve));

  return { ip: '127.0.0.1', port };
}

const FAST = { pieceTimeoutMs: 2000, backoffBaseMs: 10, concurrency: 2 };

test('a peer that refuses every connection is dropped and the download finishes on the others', async () => {
  const pieces = buildPieces(6);
  const torrent = torrentFor(pieces);
  const good = await startFakePeer(pieces);
  const sourceCounts = [];

  try {
    await withTempDir(async (outputDir) => {
      await downloadTorrent(
        torrent,
        [await deadPeer(), { ip: '127.0.0.1', port: good.address().port }],
        {
          ...FAST,
          infoHash: INFO_HASH,
          peerId: CLIENT_PEER_ID,
          outputDir,
          onSourcesChange: (counts) => sourceCounts.push(counts),
        },
      );
      assert.ok((await readFile(join(outputDir, 'output.bin'))).equals(Buffer.concat(pieces)));
      assert.deepEqual(sourceCounts.at(-1), { active: 1, dropped: 1 });
    });
  } finally {
    good.close();
  }
});

test('a piece that fails many times on the only source is retried until it gets through (Mulan case)', async () => {
  const pieces = buildPieces(2);
  const torrent = torrentFor(pieces);
  const content = Buffer.concat(pieces);
  let failuresLeft = 6; // more than the old fixed budget of max(4, sources x 2) = 4
  const webSeed = createHttpServer((req, res) => {
    if (failuresLeft > 0) {
      failuresLeft -= 1;
      req.socket.destroy(); // fetch() sees "fetch failed", like the archive.org web-seed did

      return;
    }

    const [, start, end] = /bytes=(\d+)-(\d+)/.exec(req.headers.range).map(Number);
    res.writeHead(206, { 'Content-Length': end - start + 1 });
    res.end(content.subarray(start, end + 1));
  });
  await new Promise((resolve) => webSeed.listen(0, '127.0.0.1', resolve));

  try {
    await withTempDir(async (outputDir) => {
      await downloadTorrent(torrent, [], {
        ...FAST,
        infoHash: INFO_HASH,
        peerId: CLIENT_PEER_ID,
        outputDir,
        sourceCooldownMs: 50,
        webSeedUrls: [`http://127.0.0.1:${webSeed.address().port}/`],
      });
      assert.ok((await readFile(join(outputDir, 'output.bin'))).equals(content));
      assert.equal(failuresLeft, 0);
    });
  } finally {
    webSeed.close();
  }
});

test('when every source is dead, the download fails once nothing has progressed for stallTimeoutMs', async () => {
  const torrent = torrentFor(buildPieces(2));

  await withTempDir(async (outputDir) => {
    await assert.rejects(
      downloadTorrent(torrent, [await deadPeer()], {
        ...FAST,
        infoHash: INFO_HASH,
        peerId: CLIENT_PEER_ID,
        outputDir,
        stallTimeoutMs: 500,
      }),
      (err) => /No piece completed for/.test(err.message) && /ECONNREFUSED/.test(err.message),
    );
  });
});

test('a re-announce brings new peers when the active ones run out', async () => {
  const pieces = buildPieces(3);
  const good = await startFakePeer(pieces);
  let refreshes = 0;

  try {
    await withTempDir(async (outputDir) => {
      await downloadTorrent(torrentFor(pieces), [await deadPeer()], {
        ...FAST,
        infoHash: INFO_HASH,
        peerId: CLIENT_PEER_ID,
        outputDir,
        refreshIntervalMs: 0,
        refreshSources: async () => {
          refreshes += 1;

          return [{ ip: '127.0.0.1', port: good.address().port }];
        },
      });
      assert.ok((await readFile(join(outputDir, 'output.bin'))).equals(Buffer.concat(pieces)));
      assert.ok(refreshes >= 1);
    });
  } finally {
    good.close();
  }
});

test('skipPieces are not fetched again and the existing file is written in place, not truncated', async () => {
  const pieces = buildPieces(3);
  const content = Buffer.concat(pieces);
  // the peer never serves pieces 0 and 1: the download only succeeds if it never asks
  const server = await startFakePeer(pieces, { failPieceIndexes: new Set([0, 1]) });

  try {
    await withTempDir(async (outputDir) => {
      await writeFile(join(outputDir, 'output.bin'), content.subarray(0, PIECE_LENGTH * 2));
      const result = await downloadTorrent(
        torrentFor(pieces),
        [{ ip: '127.0.0.1', port: server.address().port }],
        {
          ...FAST,
          infoHash: INFO_HASH,
          peerId: CLIENT_PEER_ID,
          outputDir,
          stallTimeoutMs: 3000,
          skipPieces: new Set([0, 1]),
        },
      );
      assert.equal(result.piecesDownloaded, 1);
      assert.ok((await readFile(join(outputDir, 'output.bin'))).equals(content));
    });
  } finally {
    server.close();
  }
});
