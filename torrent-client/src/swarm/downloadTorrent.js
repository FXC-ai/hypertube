import { open, mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { CancelledError } from '../cancelledError.js';
import { downloadPieceFromPeer } from '../peer/downloadPiece.js';
import { computeFileLayout, computePieceRanges, computeOverlaps } from '../torrentLayout.js';
import { downloadPieceFromWebSeed } from '../webseed/downloadPieceFromWebSeed.js';

export class SwarmDownloadError extends Error {
  constructor(message) {
    super(message);
    this.name = 'SwarmDownloadError';
  }
}

// Downloads every piece of `torrent` from `peers` (BitTorrent peer-wire)
// and/or `webSeedUrls` (BEP19 HTTP web-seed base URLs, #12) using a bounded
// pool of concurrent workers. Peers and web-seeds are mixed into a single
// rotation, not tried as a fallback chain -- a worker just pulls the next
// source in the list, peer-wire or web-seed, whichever comes up, so both
// are used together whenever both are available rather than one being
// abandoned in favour of the other. No persistent per-source connections
// yet (see README): every attempt is a fresh TCP connection or HTTP
// request. Pieces are written to their real file(s) under `outputDir`,
// following torrent.files -- NOT to one flat blob, since a single piece
// routinely straddles a file boundary in a multi-file torrent (every real
// fixture in this repo, archive.org's included, is multi-file). A piece
// that keeps failing (bad source, timeout, hash mismatch) is requeued
// against a different source, up to maxAttemptsPerPiece, before the whole
// download is abandoned with a SwarmDownloadError.
export async function downloadTorrent(torrent, peers, options) {
  const {
    infoHash,
    peerId,
    outputDir,
    concurrency = 10,
    pieceTimeoutMs = 20000,
    connectTimeoutMs = 5000,
    webSeedUrls = [],
    maxAttemptsPerPiece = Math.max(4, (peers.length + webSeedUrls.length) * 2),
    onProgress,
    signal,
  } = options;

  const sources = [
    ...peers.map((peer) => ({ kind: 'peer', peer })),
    ...webSeedUrls.map((baseUrl) => ({ kind: 'webseed', baseUrl })),
  ];

  if (sources.length === 0) {
    throw new SwarmDownloadError('No candidate peers or web-seed URLs to download from');
  }

  const fileLayout = computeFileLayout(torrent);
  const pieceOffsets = computePieceRanges(torrent);
  const numPieces = torrent.pieces.length;
  const queue = [...Array(numPieces).keys()];
  const attempts = new Array(numPieces).fill(0);
  let completed = 0;
  let sourceCursor = 0;
  let failure = null;

  function nextSource() {
    const source = sources[sourceCursor % sources.length];
    sourceCursor += 1;

    return source;
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
        if (failure || signal?.aborted) {
          return;
        }

        const pieceIndex = queue.shift();

        if (pieceIndex === undefined) {
          return;
        }

        const { offset, length } = pieceOffsets[pieceIndex];
        const source = nextSource();

        try {
          const buffer =
            source.kind === 'webseed'
              ? await downloadPieceFromWebSeed(
                  source.baseUrl,
                  torrent,
                  fileLayout,
                  pieceIndex,
                  offset,
                  length,
                  {
                    pieceHash: torrent.pieces[pieceIndex],
                    timeoutMs: pieceTimeoutMs,
                    signal,
                  },
                )
              : await downloadPieceFromPeer(source.peer, {
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
              `Piece ${pieceIndex} failed after ${attempts[pieceIndex]} attempt(s) across the source pool: ${err.message}`,
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

    // A zero-length file (e.g. an empty log placeholder -- archive.org's
    // multi-file torrents routinely include a few) never overlaps any
    // piece, so the loop above never calls handleFor() for it. Touch every
    // file explicitly here so the torrent's full file list actually exists
    // on disk, not just the ones pieces happened to write into.
    await Promise.all(fileLayout.map((file) => handleFor(file)));

    return { outputDir, piecesDownloaded: completed, files: fileLayout.map((f) => f.path) };
  } finally {
    await Promise.all(
      [...fileHandlePromises.values()].map((promise) => promise.then((handle) => handle.close())),
    );
  }
}
