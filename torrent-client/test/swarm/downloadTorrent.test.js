import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
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
          maxAttemptsPerPiece: 6,
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
