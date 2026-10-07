import { CancelledError } from '../cancelledError.js';
import { PeerError } from './peerError.js';
import { createPeerSession } from './peerSession.js';

export { PeerError };

// Downloads one piece from one peer over a short-lived session: handshake, wait for unchoke,
// request its blocks, verify the SHA-1. A hash mismatch is retried up to `maxAttempts`, never
// accepted. The swarm keeps sessions open across pieces instead (see peerSession.js); this is
// for a single piece, e.g. the integration tests.
export async function downloadPieceFromPeer(peer, options) {
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

  if (signal?.aborted) {
    throw new CancelledError();
  }

  const session = createPeerSession(peer, { infoHash, peerId, connectTimeoutMs });
  const timeout = AbortSignal.timeout(overallTimeoutMs);
  const pieceSignal = signal ? AbortSignal.any([signal, timeout]) : timeout;

  try {
    for (let attempt = 1; ; attempt += 1) {
      try {
        return await session.requestPiece(pieceIndex, pieceLength, pieceHash, {
          timeoutMs: overallTimeoutMs,
          signal: pieceSignal,
        });
      } catch (err) {
        if (err instanceof CancelledError && !signal?.aborted && timeout.aborted) {
          throw new PeerError('Timed out downloading piece from peer');
        }

        if (!err.hashMismatch || attempt >= maxAttempts) {
          throw err.hashMismatch
            ? new PeerError(
                `Piece ${pieceIndex} hash mismatch after ${maxAttempts} attempt(s): ${err.message}`,
                { hashMismatch: true },
              )
            : err;
        }
      }
    }
  } finally {
    session.close();
  }
}
