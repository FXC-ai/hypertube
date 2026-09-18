import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:net';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { downloadTorrent, SwarmDownloadError } from '../../src/swarm/downloadTorrent.js';
import { buildHandshake } from '../../src/peer/handshake.js';
import { encodeMessage, extractMessages, MESSAGE_ID } from '../../src/peer/messages.js';

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
    infoHash: INFO_HASH.toString('hex'),
    pieceLength: PIECE_LENGTH,
    pieces: pieces.map((p) => createHash('sha1').update(p).digest('hex')),
    totalLength,
    files: [{ path: fileName, length: totalLength }],
  };
}

// Multi-file torrent built from the same concatenated piece stream, split
// at `splitAt` bytes into two files -- deliberately not aligned to a piece
// boundary, so at least one piece straddles the file boundary.
function multiFileTorrentFor(pieces, splitAt, fileNames = ['a.bin', 'b.bin']) {
  const totalLength = pieces.reduce((sum, p) => sum + p.length, 0);
  return {
    infoHash: INFO_HASH.toString('hex'),
    pieceLength: PIECE_LENGTH,
    pieces: pieces.map((p) => createHash('sha1').update(p).digest('hex')),
    totalLength,
    files: [
      { path: fileNames[0], length: splitAt },
      { path: fileNames[1], length: totalLength - splitAt },
    ],
  };
}

// A fake peer that serves real piece bytes for whichever piece index is
// requested, with optional flakiness knobs for testing retry behaviour.
function startFakePeer(pieces, { refuseAfterHandshake = false, failPieceIndexes = new Set() } = {}) {
  const server = createServer((socket) => {
    if (refuseAfterHandshake) {
      socket.destroy();
      return;
    }
    let buffer = Buffer.alloc(0);
    let handshakeDone = false;
    socket.on('data', (chunk) => {
      buffer = Buffer.concat([buffer, chunk]);
      if (!handshakeDone) {
        if (buffer.length < 68) return;
        handshakeDone = true;
        buffer = buffer.subarray(68);
        socket.write(buildHandshake(INFO_HASH, Buffer.alloc(20, 9)));
        socket.write(encodeMessage(MESSAGE_ID.UNCHOKE));
      }
      const { messages, remaining } = extractMessages(buffer);
      buffer = remaining;
      for (const message of messages) {
        if (message.id !== MESSAGE_ID.REQUEST) continue;
        const index = message.payload.readUInt32BE(0);
        const begin = message.payload.readUInt32BE(4);
        const length = message.payload.readUInt32BE(8);
        if (failPieceIndexes.has(index)) continue; // silently drop -- simulates a peer that can't serve this piece
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

async function withTempDir(fn) {
  const dir = await mkdtemp(join(tmpdir(), 'torrent-swarm-test-'));
  try {
    return await fn(dir);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}

test('downloads every piece from a single peer and assembles a single-file torrent correctly', async () => {
  const pieces = buildPieces(4, 5000);
  const torrent = torrentFor(pieces);
  const server = await startFakePeer(pieces);

  try {
    await withTempDir(async (outputDir) => {
      const result = await downloadTorrent(torrent, [{ ip: '127.0.0.1', port: server.address().port }], {
        infoHash: INFO_HASH,
        peerId: CLIENT_PEER_ID,
        outputDir,
        concurrency: 4,
      });
      assert.equal(result.piecesDownloaded, 4);
      assert.deepEqual(result.files, ['output.bin']);

      const written = await readFile(join(outputDir, 'output.bin'));
      assert.ok(written.equals(Buffer.concat(pieces)));
    });
  } finally {
    server.close();
  }
});

test('splits a piece that straddles a file boundary across both files', async () => {
  const pieces = buildPieces(3); // 3 * 16384 = 49152 bytes total
  const splitAt = 20000; // falls inside piece index 1 (bytes 16384..32768)
  const torrent = multiFileTorrentFor(pieces, splitAt);
  const server = await startFakePeer(pieces);

  try {
    await withTempDir(async (outputDir) => {
      const result = await downloadTorrent(torrent, [{ ip: '127.0.0.1', port: server.address().port }], {
        infoHash: INFO_HASH,
        peerId: CLIENT_PEER_ID,
        outputDir,
        concurrency: 4,
      });
      assert.equal(result.piecesDownloaded, 3);

      const whole = Buffer.concat(pieces);
      const fileA = await readFile(join(outputDir, 'a.bin'));
      const fileB = await readFile(join(outputDir, 'b.bin'));
      assert.equal(fileA.length, splitAt);
      assert.equal(fileB.length, whole.length - splitAt);
      assert.ok(fileA.equals(whole.subarray(0, splitAt)));
      assert.ok(fileB.equals(whole.subarray(splitAt)));
    });
  } finally {
    server.close();
  }
});

test('writes a file nested under a subdirectory per its torrent path', async () => {
  const pieces = buildPieces(2);
  const torrent = torrentFor(pieces, { fileName: 'nested/dir/movie.mp4' });
  const server = await startFakePeer(pieces);

  try {
    await withTempDir(async (outputDir) => {
      await downloadTorrent(torrent, [{ ip: '127.0.0.1', port: server.address().port }], {
        infoHash: INFO_HASH,
        peerId: CLIENT_PEER_ID,
        outputDir,
        concurrency: 2,
      });
      const written = await readFile(join(outputDir, 'nested/dir/movie.mp4'));
      assert.ok(written.equals(Buffer.concat(pieces)));
    });
  } finally {
    server.close();
  }
});

test('spreads pieces across multiple peers concurrently', async () => {
  const pieces = buildPieces(6);
  const torrent = torrentFor(pieces);
  const serverA = await startFakePeer(pieces);
  const serverB = await startFakePeer(pieces);

  try {
    await withTempDir(async (outputDir) => {
      await downloadTorrent(
        torrent,
        [
          { ip: '127.0.0.1', port: serverA.address().port },
          { ip: '127.0.0.1', port: serverB.address().port },
        ],
        { infoHash: INFO_HASH, peerId: CLIENT_PEER_ID, outputDir, concurrency: 4 },
      );
      const written = await readFile(join(outputDir, 'output.bin'));
      assert.ok(written.equals(Buffer.concat(pieces)));
    });
  } finally {
    serverA.close();
    serverB.close();
  }
});

test('retries a piece against another peer when one peer cannot serve it', async () => {
  const pieces = buildPieces(3);
  const torrent = torrentFor(pieces);
  const flakyPeer = await startFakePeer(pieces, { failPieceIndexes: new Set([1]) });
  const reliablePeer = await startFakePeer(pieces);

  try {
    await withTempDir(async (outputDir) => {
      const result = await downloadTorrent(
        torrent,
        [
          { ip: '127.0.0.1', port: flakyPeer.address().port },
          { ip: '127.0.0.1', port: reliablePeer.address().port },
        ],
        { infoHash: INFO_HASH, peerId: CLIENT_PEER_ID, outputDir, concurrency: 2, pieceTimeoutMs: 1500 },
      );
      assert.equal(result.piecesDownloaded, 3);
      const written = await readFile(join(outputDir, 'output.bin'));
      assert.ok(written.equals(Buffer.concat(pieces)));
    });
  } finally {
    flakyPeer.close();
    reliablePeer.close();
  }
});

test('ignores peers that refuse the connection and completes via the working ones', async () => {
  const pieces = buildPieces(2);
  const torrent = torrentFor(pieces);
  const deadPeer = await startFakePeer(pieces, { refuseAfterHandshake: true });
  const workingPeer = await startFakePeer(pieces);

  try {
    await withTempDir(async (outputDir) => {
      const result = await downloadTorrent(
        torrent,
        [
          { ip: '127.0.0.1', port: deadPeer.address().port },
          { ip: '127.0.0.1', port: workingPeer.address().port },
        ],
        { infoHash: INFO_HASH, peerId: CLIENT_PEER_ID, outputDir, concurrency: 2, pieceTimeoutMs: 1500 },
      );
      assert.equal(result.piecesDownloaded, 2);
    });
  } finally {
    deadPeer.close();
    workingPeer.close();
  }
});

test('rejects with SwarmDownloadError when no peer can ever serve a piece', async () => {
  const pieces = buildPieces(2);
  const torrent = torrentFor(pieces);
  const uselessPeer = await startFakePeer(pieces, { failPieceIndexes: new Set([0, 1]) });

  try {
    await withTempDir(async (outputDir) => {
      await assert.rejects(
        () => downloadTorrent(torrent, [{ ip: '127.0.0.1', port: uselessPeer.address().port }], {
          infoHash: INFO_HASH,
          peerId: CLIENT_PEER_ID,
          outputDir,
          concurrency: 2,
          pieceTimeoutMs: 500,
          maxAttemptsPerPiece: 2,
        }),
        SwarmDownloadError,
      );
    });
  } finally {
    uselessPeer.close();
  }
});

test('reports progress as pieces complete', async () => {
  const pieces = buildPieces(3);
  const torrent = torrentFor(pieces);
  const server = await startFakePeer(pieces);
  const progressUpdates = [];

  try {
    await withTempDir(async (outputDir) => {
      await downloadTorrent(torrent, [{ ip: '127.0.0.1', port: server.address().port }], {
        infoHash: INFO_HASH,
        peerId: CLIENT_PEER_ID,
        outputDir,
        concurrency: 1,
        onProgress: (update) => progressUpdates.push({ ...update }),
      });
    });
  } finally {
    server.close();
  }

  assert.equal(progressUpdates.length, 3);
  assert.deepEqual(progressUpdates.map((u) => u.completed), [1, 2, 3]);
  assert.ok(progressUpdates.every((u) => u.total === 3));
});
