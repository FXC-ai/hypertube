import { createHash } from 'node:crypto';
import { CancelledError } from '../cancelledError.js';
import { computeOverlaps } from '../torrentLayout.js';

// Same flags as PeerError: connectionFailure (server unreachable), hashMismatch and actualHash.
export class WebSeedError extends Error {
  constructor(
    message,
    { connectionFailure = false, hashMismatch = false, actualHash = null } = {},
  ) {
    super(message);
    this.name = 'WebSeedError';
    this.connectionFailure = connectionFailure;
    this.hashMismatch = hashMismatch;
    this.actualHash = actualHash;
  }
}

const DEFAULT_TIMEOUT_MS = 20000;

// Downloads a run of consecutive pieces via BEP19 with one ranged GET per file the run overlaps,
// at <baseUrl><torrent.name>/<file.path> (the archive.org convention). Bytes are cut into pieces
// as they arrive: each piece is verified against its SHA-1 and handed to `onPiece` at once (a
// corrupted one to `onBadPiece`, with its bytes), so a long run does not delay the first piece. One request per
// piece (the old way) paid a round trip, and a redirect, for every 128 KiB to 2 MiB.
//
// `urlCache` (a Map shared across calls) remembers where a redirect led: archive.org/download/
// answers 302 to the storage node holding the item. `idleTimeoutMs` fails a response that stops
// sending, however long the run is. A network error rejects the whole call; the caller retries
// the pieces it did not get.
export async function downloadPiecesFromWebSeed(
  baseUrl,
  torrent,
  fileLayout,
  pieceRanges,
  pieceIndexes,
  options,
) {
  const {
    onPiece,
    onBadPiece = () => {},
    idleTimeoutMs = DEFAULT_TIMEOUT_MS,
    fetchImpl = fetch,
    urlCache = new Map(),
    signal,
  } = options;

  if (signal?.aborted) {
    throw new CancelledError();
  }

  const first = pieceRanges[pieceIndexes[0]];
  const last = pieceRanges[pieceIndexes.at(-1)];
  const runStart = first.offset;
  const runLength = last.offset + last.length - runStart;
  let pieceCursor = 0;
  let pending = [];
  let pendingLength = 0;

  // Called with every chunk of the run, in order: emits each piece once all its bytes are in.
  async function consume(chunk) {
    pending.push(chunk);
    pendingLength += chunk.length;

    while (pieceCursor < pieceIndexes.length) {
      const pieceIndex = pieceIndexes[pieceCursor];
      const { length } = pieceRanges[pieceIndex];

      if (pendingLength < length) {
        return;
      }

      const joined = pending.length === 1 ? pending[0] : Buffer.concat(pending);
      const piece = joined.subarray(0, length);
      pending = joined.length > length ? [joined.subarray(length)] : [];
      pendingLength -= length;
      pieceCursor += 1;
      const expected = torrent.pieces?.[pieceIndex];
      const actual = expected ? createHash('sha1').update(piece).digest('hex') : null;

      if (expected && actual !== expected) {
        await onBadPiece(
          pieceIndex,
          new WebSeedError(
            `Piece ${pieceIndex} hash mismatch via web-seed: expected ${expected}, got ${actual}`,
            { hashMismatch: true, actualHash: actual },
          ),
          Buffer.from(piece),
        );
      } else {
        await onPiece(pieceIndex, Buffer.from(piece));
      }
    }
  }

  for (const overlap of computeOverlaps(fileLayout, runStart, runLength)) {
    const url = buildFileUrl(baseUrl, torrent.name, overlap.file.path);
    await fetchRange(url, overlap.fileOffset, overlap.length, consume, {
      idleTimeoutMs,
      fetchImpl,
      urlCache,
      signal,
    });
  }
}

// Streams [start, start + length) of one file into `consume`, failing if no byte arrives for
// `idleTimeoutMs`.
async function fetchRange(
  url,
  start,
  length,
  consume,
  { idleTimeoutMs, fetchImpl, urlCache, signal },
) {
  const target = urlCache.get(url) ?? url;
  const idle = new AbortController();
  let idleTimer = setTimeout(() => idle.abort(), idleTimeoutMs);
  const requestSignal = signal ? AbortSignal.any([signal, idle.signal]) : idle.signal;
  const restartIdle = () => {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => idle.abort(), idleTimeoutMs);
  };

  function describe(err) {
    if (signal?.aborted) {
      return new CancelledError();
    }

    if (idle.signal.aborted) {
      return new WebSeedError(
        `Web-seed ${target} stalled: nothing received for ${idleTimeoutMs} ms`,
      );
    }

    if (target !== url) {
      urlCache.delete(url); // the node a redirect led to may be gone: start from the base again
    }

    // fetch() hides the real network error (ECONNRESET, ENOTFOUND...) in err.cause.
    return new WebSeedError(`Web-seed request failed for ${target}: ${describeFetchError(err)}`, {
      connectionFailure: true,
    });
  }

  try {
    let response;

    try {
      response = await fetchImpl(target, {
        headers: { Range: `bytes=${start}-${start + length - 1}` },
        signal: requestSignal,
      });
    } catch (err) {
      throw describe(err);
    }

    if (response.status !== 206 && !(response.status === 200 && start === 0)) {
      await response.body?.cancel().catch(() => {});

      throw new WebSeedError(`Web-seed responded with status ${response.status} for ${target}`);
    }

    if (response.url && response.url !== target) {
      urlCache.set(url, response.url);
    }

    let received = 0;

    try {
      for await (const chunk of response.body) {
        restartIdle();
        const wanted = Math.min(chunk.length, length - received);
        received += wanted;
        await consume(Buffer.from(chunk.buffer, chunk.byteOffset, wanted));

        if (received >= length) {
          break; // a 200 sends the whole file: stop once the range is in
        }
      }
    } catch (err) {
      throw err instanceof WebSeedError || err instanceof CancelledError ? err : describe(err);
    }

    if (received < length) {
      throw new WebSeedError(
        `Web-seed returned ${received} bytes, expected ${length}, for ${target} (range ${start}-${start + length - 1})`,
      );
    }
  } finally {
    clearTimeout(idleTimer);
  }
}

// One piece, for callers that do not batch (and the tests).
export async function downloadPieceFromWebSeed(
  baseUrl,
  torrent,
  fileLayout,
  pieceIndex,
  pieceOffset,
  pieceLength,
  options = {},
) {
  const { pieceHash, timeoutMs = DEFAULT_TIMEOUT_MS, fetchImpl = fetch, signal } = options;
  let result = null;
  let badPiece = null;

  await downloadPiecesFromWebSeed(
    baseUrl,
    { name: torrent.name, pieces: { [pieceIndex]: pieceHash } },
    fileLayout,
    { [pieceIndex]: { offset: pieceOffset, length: pieceLength } },
    [pieceIndex],
    {
      onPiece: (_, buffer) => {
        result = buffer;
      },
      onBadPiece: (_, err) => {
        badPiece = err;
      },
      idleTimeoutMs: timeoutMs,
      fetchImpl,
      signal,
    },
  );

  if (badPiece) {
    throw badPiece;
  }

  return result;
}

export function describeFetchError(err) {
  const cause = err.cause;

  if (!cause) {
    return err.message;
  }

  return `${err.message} (${cause.code ?? cause.message ?? String(cause)})`;
}

function buildFileUrl(baseUrl, torrentName, filePath) {
  const normalizedBase = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
  const segments = [torrentName, ...filePath.split('/')].map(encodeURIComponent);

  return normalizedBase + segments.join('/');
}
