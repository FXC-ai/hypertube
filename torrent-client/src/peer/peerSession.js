import { createHash } from 'node:crypto';
import { connect } from 'node:net';
import { CancelledError } from '../cancelledError.js';
import { buildHandshake, parseHandshake } from './handshake.js';
import {
  encodeInterested,
  encodeKeepAlive,
  encodeMessage,
  encodeRequest,
  extractMessages,
  KEEP_ALIVE,
  MESSAGE_ID,
  parseHave,
  parsePiece,
} from './messages.js';
import { PeerError } from './peerError.js';

const BLOCK_SIZE = 16384;
const HANDSHAKE_LENGTH = 68;
const KEEP_ALIVE_INTERVAL_MS = 60000;
const TIMEOUT_CHECK_MS = 250;

// One long-lived peer-wire connection: handshake and `interested` once, then any number of
// pieces requested over it, several at a time, with up to `maxOutstandingBlocks` 16 KiB block
// requests in flight across them. Opening a connection per piece (the old way) paid a TCP
// connect, a handshake and the wait for unchoke on every piece, and seeds that limit their
// connections ended up closing on us.
//
// A piece request fails on its own (the connection stays open) when the peer does not have the
// piece, when nothing arrives for it for `timeoutMs` after its blocks were requested, or when we
// stay choked for `timeoutMs`. A dead connection fails every piece on it; connect errors carry
// `connectionFailure` so the source pool can set the peer aside.
export function createPeerSession(peer, options) {
  const {
    infoHash,
    peerId,
    connectTimeoutMs = 5000,
    maxOutstandingBlocks = 256,
    onClose,
  } = options;
  const label = `${peer.ip}:${peer.port}`;
  const socket = connect({ host: peer.ip, port: peer.port, timeout: connectTimeoutMs });
  const requests = []; // pieces being fetched, in the order they were asked for
  let buffer = Buffer.alloc(0);
  let connected = false;
  let handshakeDone = false;
  let choked = true;
  let closedError = null;
  let have = null; // Set of piece indexes from bitfield/have, null while the peer sent neither
  let outstandingBlocks = 0;
  let resolveReady;
  let rejectReady;
  const ready = new Promise((resolve, reject) => {
    resolveReady = resolve;
    rejectReady = reject;
  });
  ready.catch(() => {});

  const keepAliveTimer = setInterval(() => {
    if (handshakeDone) {
      socket.write(encodeKeepAlive());
    }
  }, KEEP_ALIVE_INTERVAL_MS);
  const timeoutTimer = setInterval(checkTimeouts, TIMEOUT_CHECK_MS);
  keepAliveTimer.unref?.();
  timeoutTimer.unref?.();

  socket.on('connect', () => {
    connected = true;
    socket.setTimeout(0);
    socket.write(buildHandshake(infoHash, peerId));
  });
  // The socket timeout is only armed until 'connect', so it always means "unreachable".
  socket.on('timeout', () =>
    close(new PeerError(`Connection to ${label} timed out`, { connectionFailure: true })),
  );
  socket.on('error', (err) =>
    close(
      new PeerError(`Socket error with ${label}: ${err.message}`, {
        connectionFailure: !connected,
      }),
    ),
  );
  // Closing before the handshake is as good as refusing the connection.
  socket.on('close', () =>
    close(
      new PeerError(`Connection to ${label} closed before the piece was complete`, {
        connectionFailure: !handshakeDone,
      }),
    ),
  );
  socket.on('data', onData);

  function onData(chunk) {
    buffer = buffer.length === 0 ? chunk : Buffer.concat([buffer, chunk]);

    if (!handshakeDone) {
      if (buffer.length < HANDSHAKE_LENGTH) {
        return;
      }

      let parsed;

      try {
        parsed = parseHandshake(buffer);
      } catch (err) {
        close(new PeerError(`Malformed handshake from ${label}: ${err.message}`));

        return;
      }

      if (!parsed.infoHash.equals(infoHash)) {
        close(new PeerError(`Peer ${label} handshake info_hash mismatch`));

        return;
      }

      handshakeDone = true;
      buffer = buffer.subarray(parsed.length);
      socket.write(encodeInterested());
      resolveReady();
    }

    const { messages, remaining } = extractMessages(buffer);
    buffer = remaining;

    for (const message of messages) {
      if (closedError) {
        return;
      }

      handleMessage(message);
    }

    pump();
  }

  function handleMessage({ id, payload }) {
    if (id === KEEP_ALIVE) {
      return;
    }

    if (id === MESSAGE_ID.UNCHOKE) {
      choked = false;

      for (const request of requests) {
        request.lastActivityAt = 0; // the timeout restarts when its blocks are requested again
      }
    } else if (id === MESSAGE_ID.CHOKE) {
      // A choke discards every request the peer had queued (BEP3): ask again after unchoke.
      choked = true;
      outstandingBlocks = 0;

      for (const request of requests) {
        request.nextBegin = 0; // blocks may have arrived out of order: pump() skips those we have
        request.inFlightBlocks = 0;
        request.chokedSince = Date.now();
      }
    } else if (id === MESSAGE_ID.BITFIELD) {
      have = new Set();

      for (let byte = 0; byte < payload.length; byte += 1) {
        for (let bit = 0; bit < 8; bit += 1) {
          if (payload[byte] & (0x80 >> bit)) {
            have.add(byte * 8 + bit);
          }
        }
      }

      failPiecesThePeerLacks();
    } else if (id === MESSAGE_ID.HAVE) {
      have ??= new Set();
      have.add(parseHave(payload));
    } else if (id === MESSAGE_ID.PIECE) {
      onBlock(parsePiece(payload));
    }
  }

  function onBlock({ index, begin, block }) {
    const request = requests.find((r) => r.pieceIndex === index);

    if (!request || request.blocks.has(begin)) {
      return; // a block we no longer want (cancelled piece, or a duplicate after a re-request)
    }

    request.blocks.set(begin, block);
    request.received += block.length;
    request.inFlightBlocks = Math.max(0, request.inFlightBlocks - 1);
    request.lastActivityAt = Date.now();
    outstandingBlocks = Math.max(0, outstandingBlocks - 1);

    if (request.received < request.pieceLength) {
      return;
    }

    const sorted = [...request.blocks.keys()].sort((a, b) => a - b);
    const piece = Buffer.concat(sorted.map((offset) => request.blocks.get(offset)));
    const actualHash = createHash('sha1').update(piece).digest('hex');

    if (actualHash === request.pieceHash) {
      settle(request, null, piece);

      return;
    }

    settle(
      request,
      new PeerError(
        `Piece ${index} hash mismatch from ${label}: expected ${request.pieceHash}, got ${actualHash}`,
        { hashMismatch: true, actualHash },
      ),
    );
  }

  // Requests blocks, oldest piece first, while the pipeline has room.
  function pump() {
    if (!handshakeDone || choked || closedError) {
      return;
    }

    for (const request of requests) {
      while (outstandingBlocks < maxOutstandingBlocks && request.nextBegin < request.pieceLength) {
        const length = Math.min(BLOCK_SIZE, request.pieceLength - request.nextBegin);

        if (!request.blocks.has(request.nextBegin)) {
          socket.write(encodeRequest(request.pieceIndex, request.nextBegin, length));
          outstandingBlocks += 1;
          request.inFlightBlocks += 1;
        }

        if (request.inFlightBlocks === 1 && request.lastActivityAt === 0) {
          request.lastActivityAt = Date.now();
        }

        request.nextBegin += length;
      }

      if (outstandingBlocks >= maxOutstandingBlocks) {
        return;
      }
    }
  }

  function checkTimeouts() {
    const now = Date.now();

    for (const request of [...requests]) {
      if (choked || !handshakeDone) {
        if (now - request.chokedSince > request.timeoutMs) {
          // A peer that never unchokes us is as useless as an unreachable one.
          settle(
            request,
            new PeerError(`Peer ${label} kept us choked`, { connectionFailure: true }),
          );
        }
      } else if (request.lastActivityAt > 0 && now - request.lastActivityAt > request.timeoutMs) {
        settle(request, new PeerError(`Peer ${label} did not send piece ${request.pieceIndex}`));
      }
    }
  }

  function failPiecesThePeerLacks() {
    for (const request of [...requests]) {
      if (!have.has(request.pieceIndex)) {
        settle(request, new PeerError(`Peer ${label} does not have piece ${request.pieceIndex}`));
      }
    }
  }

  // Removes a request, tells the peer to stop sending its blocks, and resolves or rejects it.
  function settle(request, err, piece) {
    const position = requests.indexOf(request);

    if (position === -1) {
      return;
    }

    requests.splice(position, 1);
    request.cleanup();

    if (!closedError && request.inFlightBlocks > 0) {
      for (let begin = 0; begin < request.nextBegin; begin += BLOCK_SIZE) {
        if (!request.blocks.has(begin)) {
          const length = Math.min(BLOCK_SIZE, request.pieceLength - begin);
          const payload = Buffer.alloc(12);
          payload.writeUInt32BE(request.pieceIndex, 0);
          payload.writeUInt32BE(begin, 4);
          payload.writeUInt32BE(length, 8);
          socket.write(encodeMessage(MESSAGE_ID.CANCEL, payload));
        }
      }

      outstandingBlocks = Math.max(0, outstandingBlocks - request.inFlightBlocks);
    }

    if (err) {
      request.reject(err);
    } else {
      request.resolve(piece);
    }

    pump();
  }

  function close(err) {
    if (closedError) {
      return;
    }

    closedError = err;
    clearInterval(keepAliveTimer);
    clearInterval(timeoutTimer);
    socket.removeAllListeners('data');
    socket.destroy();
    rejectReady(err);

    for (const request of [...requests]) {
      settle(request, err);
    }

    onClose?.(err);
  }

  // Resolves with the verified piece. `signal` aborts this piece only (the connection stays).
  function requestPiece(pieceIndex, pieceLength, pieceHash, { timeoutMs = 20000, signal } = {}) {
    return new Promise((resolve, reject) => {
      if (closedError) {
        reject(closedError);

        return;
      }

      if (signal?.aborted) {
        reject(new CancelledError());

        return;
      }

      if (have && !have.has(pieceIndex)) {
        reject(new PeerError(`Peer ${label} does not have piece ${pieceIndex}`));

        return;
      }

      const request = {
        pieceIndex,
        pieceLength,
        pieceHash,
        timeoutMs,
        blocks: new Map(),
        received: 0,
        nextBegin: 0,
        inFlightBlocks: 0,
        lastActivityAt: 0,
        chokedSince: Date.now(),
        resolve,
        reject,
        cleanup: () => signal?.removeEventListener('abort', onAbort),
      };

      function onAbort() {
        settle(request, new CancelledError());
      }

      signal?.addEventListener('abort', onAbort);
      requests.push(request);
      pump();
    });
  }

  return {
    label,
    ready,
    requestPiece,
    isClosed: () => closedError !== null,
    close: () => close(new CancelledError()),
  };
}
