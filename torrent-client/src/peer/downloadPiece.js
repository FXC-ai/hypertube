import { connect } from 'node:net';
import { createHash } from 'node:crypto';
import { buildHandshake, parseHandshake } from './handshake.js';
import { extractMessages, encodeInterested, encodeRequest, parsePiece, MESSAGE_ID, KEEP_ALIVE } from './messages.js';
import { CancelledError } from '../cancelledError.js';

const BLOCK_SIZE = 16384;
const MAX_PIPELINED_REQUESTS = 5;
const HANDSHAKE_LENGTH = 68;

export class PeerError extends Error {
  constructor(message) {
    super(message);
    this.name = 'PeerError';
  }
}

// Connects to a single peer, performs the handshake, waits to be unchoked,
// then requests every 16KB block of one piece, verifying its SHA-1 against
// `pieceHash` before returning it. A hash mismatch is never accepted
// silently: the piece is re-requested from scratch, up to `maxAttempts`
// times, before giving up.
export function downloadPieceFromPeer(peer, options) {
  const {
    infoHash,
    peerId,
    pieceIndex,
    pieceLength,
    pieceHash,
    maxAttempts = 2,
    connectTimeoutMs = 5000,
    overallTimeoutMs = 20000,
    signal,
  } = options;

  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(new CancelledError());
      return;
    }

    const socket = connect({ host: peer.ip, port: peer.port, timeout: connectTimeoutMs });

    let buffer = Buffer.alloc(0);
    let handshakeDone = false;
    let unchoked = false;
    let settled = false;
    let attemptsLeft = maxAttempts;
    let blocks = new Map();
    let nextRequestBegin = 0;
    let outstanding = 0;

    const overallTimer = setTimeout(() => fail(new PeerError('Timed out downloading piece from peer')), overallTimeoutMs);

    function cleanup() {
      clearTimeout(overallTimer);
      signal?.removeEventListener('abort', onAbort);
      socket.removeAllListeners();
      socket.destroy();
    }

    function fail(err) {
      if (settled) return;
      settled = true;
      cleanup();
      reject(err);
    }

    function succeed(pieceBuffer) {
      if (settled) return;
      settled = true;
      cleanup();
      resolve(pieceBuffer);
    }

    function onAbort() {
      fail(new CancelledError());
    }
    signal?.addEventListener('abort', onAbort);

    socket.on('connect', () => {
      socket.setTimeout(0);
      socket.write(buildHandshake(infoHash, peerId));
    });
    socket.on('timeout', () => fail(new PeerError(`Connection to ${peer.ip}:${peer.port} timed out`)));
    socket.on('error', (err) => fail(new PeerError(`Socket error with ${peer.ip}:${peer.port}: ${err.message}`)));
    socket.on('close', () => fail(new PeerError(`Connection to ${peer.ip}:${peer.port} closed before the piece was complete`)));

    socket.on('data', (chunk) => {
      buffer = Buffer.concat([buffer, chunk]);

      if (!handshakeDone) {
        if (buffer.length < HANDSHAKE_LENGTH) {
          return;
        }
        let parsed;
        try {
          parsed = parseHandshake(buffer);
        } catch (err) {
          fail(new PeerError(`Malformed handshake from ${peer.ip}:${peer.port}: ${err.message}`));
          return;
        }
        if (!parsed.infoHash.equals(infoHash)) {
          fail(new PeerError(`Peer ${peer.ip}:${peer.port} handshake info_hash mismatch`));
          return;
        }
        handshakeDone = true;
        buffer = buffer.subarray(parsed.length);
        socket.write(encodeInterested());
      }

      const { messages, remaining } = extractMessages(buffer);
      buffer = remaining;

      for (const message of messages) {
        if (settled) return;
        if (message.id === KEEP_ALIVE) {
          continue;
        }
        if (message.id === MESSAGE_ID.UNCHOKE) {
          unchoked = true;
          requestMore();
        } else if (message.id === MESSAGE_ID.CHOKE) {
          unchoked = false;
        } else if (message.id === MESSAGE_ID.PIECE) {
          const { index, begin, block } = parsePiece(message.payload);
          if (index !== pieceIndex) continue;
          outstanding = Math.max(0, outstanding - 1);
          blocks.set(begin, block);
          if (isComplete()) {
            handlePieceComplete();
          } else {
            requestMore();
          }
        }
      }
    });

    function isComplete() {
      let have = 0;
      for (const block of blocks.values()) have += block.length;
      return have >= pieceLength;
    }

    function requestMore() {
      if (!unchoked || settled) return;
      while (outstanding < MAX_PIPELINED_REQUESTS && nextRequestBegin < pieceLength) {
        const length = Math.min(BLOCK_SIZE, pieceLength - nextRequestBegin);
        socket.write(encodeRequest(pieceIndex, nextRequestBegin, length));
        outstanding += 1;
        nextRequestBegin += length;
      }
    }

    function handlePieceComplete() {
      const sortedBegins = [...blocks.keys()].sort((a, b) => a - b);
      const pieceBuffer = Buffer.concat(sortedBegins.map((begin) => blocks.get(begin)));
      const actualHash = createHash('sha1').update(pieceBuffer).digest('hex');

      if (actualHash === pieceHash) {
        succeed(pieceBuffer);
        return;
      }

      attemptsLeft -= 1;
      if (attemptsLeft <= 0) {
        fail(new PeerError(`Piece ${pieceIndex} hash mismatch after ${maxAttempts} attempt(s): expected ${pieceHash}, got ${actualHash}`));
        return;
      }
      // Reject the corrupt piece and redownload it from scratch rather than
      // accepting it silently.
      blocks = new Map();
      nextRequestBegin = 0;
      outstanding = 0;
      requestMore();
    }
  });
}
