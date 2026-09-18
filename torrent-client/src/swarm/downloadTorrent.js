import { open, mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { downloadPieceFromPeer } from '../peer/downloadPiece.js';
import { CancelledError } from '../cancelledError.js';
import { computeFileLayout, computePieceRanges, computeOverlaps } from '../torrentLayout.js';

export class SwarmDownloadError extends Error {
  constructor(message) {
    super(message);
    this.name = 'SwarmDownloadError';
  }
}

// Downloads every piece of `torrent` from `peers` (a flat candidate list --
// no persistent per-peer connections yet, see README) using a bounded pool
// of concurrent workers. Pieces are written to their real file(s) under
// `outputDir`, following torrent.files -- NOT to one flat blob, since a
// single piece routinely straddles a file boundary in a multi-file torrent
// (every real fixture in this repo, archive.org's included, is multi-file).
// A piece that keeps failing (bad peer, timeout, hash mismatch already
// retried inside downloadPieceFromPeer) is requeued against a different
// peer, up to maxAttemptsPerPiece, before the whole download is abandoned
// with a SwarmDownloadError.
export async function downloadTorrent(torrent, peers, options) {
  const {
    infoHash,
    peerId,
    outputDir,
    concurrency = 10,
    pieceTimeoutMs = 20000,
    connectTimeoutMs = 5000,
    maxAttemptsPerPiece = Math.max(4, peers.length * 2),
    onProgress,
    signal,
  } = options;

  if (peers.length === 0) {
    throw new SwarmDownloadError('No candidate peers to download from');
  }

  const fileLayout = computeFileLayout(torrent);
  const pieceOffsets = computePieceRanges(torrent);
  const numPieces = torrent.pieces.length;
  const queue = [...Array(numPieces).keys()];
  const attempts = new Array(numPieces).fill(0);
  let completed = 0;
  let peerCursor = 0;
  let failure = null;

  function nextPeer() {
    const peer = peers[peerCursor % peers.length];
    peerCursor += 1;
    return peer;
  }

  // Maps to in-flight *promises*, not resolved handles: two workers can ask
  // for the same file's handle in the same tick, before either has awaited
  // anything. Storing the promise synchronously on the first call means the
  // second caller reuses it instead of racing its own open(path, 'w') --
  // which would truncate the file a second time and lose whatever the first
  // handle had already written.
  const fileHandlePromises = new Map();
  function handleFor(file) {
    let promise = fileHandlePromises.get(file.path);
    if (!promise) {
      promise = (async () => {
        const fullPath = join(outputDir, file.path);
        await mkdir(dirname(fullPath), { recursive: true });
        return open(fullPath, 'w');
      })();
      fileHandlePromises.set(file.path, promise);
    }
    return promise;
  }

  try {
    async function worker() {
      for (;;) {
        if (failure || signal?.aborted) return;
        const pieceIndex = queue.shift();
        if (pieceIndex === undefined) return;

        const { offset, length } = pieceOffsets[pieceIndex];
        const peer = nextPeer();
        try {
          const buffer = await downloadPieceFromPeer(peer, {
            infoHash,
            peerId,
            pieceIndex,
            pieceLength: length,
            pieceHash: torrent.pieces[pieceIndex],
            connectTimeoutMs,
            overallTimeoutMs: pieceTimeoutMs,
            signal,
          });
          for (const overlap of computeOverlaps(fileLayout, offset, buffer.length)) {
            const handle = await handleFor(overlap.file);
            const data = buffer.subarray(overlap.rangeOffset, overlap.rangeOffset + overlap.length);
            await handle.write(data, 0, data.length, overlap.fileOffset);
          }
          completed += 1;
          onProgress?.({ completed, total: numPieces, pieceIndex });
        } catch (err) {
          if (err instanceof CancelledError) {
            return; // cancelled, not failed -- don't requeue, don't count as an attempt
          }
          attempts[pieceIndex] += 1;
          if (attempts[pieceIndex] >= maxAttemptsPerPiece) {
            failure = new SwarmDownloadError(
              `Piece ${pieceIndex} failed after ${attempts[pieceIndex]} attempt(s) across the peer pool: ${err.message}`,
            );
            return;
          }
          queue.push(pieceIndex);
        }
      }
    }

    const workerCount = Math.max(1, Math.min(concurrency, numPieces));
    await Promise.all(Array.from({ length: workerCount }, worker));

    if (signal?.aborted) {
      throw new CancelledError();
    }
    if (failure) {
      throw failure;
    }
    if (completed !== numPieces) {
      throw new SwarmDownloadError(`Download incomplete: ${completed}/${numPieces} pieces`);
    }

    return { outputDir, piecesDownloaded: completed, files: fileLayout.map((f) => f.path) };
  } finally {
    await Promise.all([...fileHandlePromises.values()].map((promise) => promise.then((handle) => handle.close())));
  }
}

