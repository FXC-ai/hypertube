import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:net';
import { createHash } from 'node:crypto';
import { downloadPieceFromPeer } from '../../src/peer/downloadPiece.js';
import { buildHandshake } from '../../src/peer/handshake.js';
import { encodeMessage, extractMessages, MESSAGE_ID } from '../../src/peer/messages.js';
import { CancelledError } from '../../src/cancelledError.js';

const INFO_HASH = Buffer.from('0102030405060708090a0b0c0d0e0f1011121314', 'hex');
const CLIENT_PEER_ID = Buffer.from('-HT0001-abcdefghijkl', 'ascii');
const PIECE_LENGTH = 32768; // 2 blocks of 16384
const PIECE_INDEX = 0;

function piecePayload(index, begin, block) {
  const header = Buffer.alloc(8);
  header.writeUInt32BE(index, 0);
  header.writeUInt32BE(begin, 4);
  return Buffer.concat([header, block]);
}

function sha1Hex(buffer) {
  return createHash('sha1').update(buffer).digest('hex');
}

// A small scriptable fake peer: sends handshake + unchoke on connect, and
// for each incoming 'request' message calls `respond(index, begin, length)`
// to decide what block bytes to send back (or nothing, to simulate a peer
// that ignores a request).
function startFakePeer(respond) {
  const server = createServer((socket) => {
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
        if (message.id === MESSAGE_ID.REQUEST) {
          const index = message.payload.readUInt32BE(0);
          const begin = message.payload.readUInt32BE(4);
          const length = message.payload.readUInt32BE(8);
          const block = respond(index, begin, length);
          if (block) {
            socket.write(encodeMessage(MESSAGE_ID.PIECE, piecePayload(index, begin, block)));
          }
        }
      }
    });
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

function correctPieceBytes() {
  const block0 = Buffer.alloc(16384, 0xaa);
  const block1 = Buffer.alloc(16384, 0xbb);
  return { full: Buffer.concat([block0, block1]), block0, block1 };
}

test('rejects a piece with a mismatched hash and redownloads it instead of accepting it silently', async () => {
  const { full, block0, block1 } = correctPieceBytes();
  const pieceHash = sha1Hex(full);
  let attempt = 0;
  const server = await startFakePeer((index, begin, length) => {
    if (begin === 0) {
      attempt += 1;
      // first attempt: corrupt the first block; second attempt: correct bytes
      return attempt <= 1 ? Buffer.alloc(length, 0xff) : block0.subarray(0, length);
    }
    return block1.subarray(0, length);
  });
  const { port } = server.address();

  try {
    const result = await downloadPieceFromPeer(
      { ip: '127.0.0.1', port },
      { infoHash: INFO_HASH, peerId: CLIENT_PEER_ID, pieceIndex: PIECE_INDEX, pieceLength: PIECE_LENGTH, pieceHash, maxAttempts: 2 },
    );
    assert.ok(result.equals(full));
    assert.ok(attempt >= 2, 'expected the peer to have been asked for the first block more than once');
  } finally {
    server.close();
  }
});

test('rejects with CancelledError and stops promptly when the signal is aborted mid-download', async () => {
  const { block0, block1 } = correctPieceBytes();
  const server = await startFakePeer((index, begin, length) => {
    // answer the first block only, then go silent -- forces the download to
    // still be in flight when we abort
    if (begin === 0) return block0.subarray(0, length);
    return null;
  });
  const { port } = server.address();
  const controller = new AbortController();
  const start = Date.now();

  try {
    setTimeout(() => controller.abort(), 100);
    await assert.rejects(
      () => downloadPieceFromPeer(
        { ip: '127.0.0.1', port },
        {
          infoHash: INFO_HASH,
          peerId: CLIENT_PEER_ID,
          pieceIndex: PIECE_INDEX,
          pieceLength: PIECE_LENGTH,
          pieceHash: sha1Hex(Buffer.concat([block0, block1])),
          overallTimeoutMs: 10000,
          signal: controller.signal,
        },
      ),
      CancelledError,
    );
    assert.ok(Date.now() - start < 3000, 'should reject promptly on abort, not wait for the overall timeout');
  } finally {
    server.close();
  }
});
