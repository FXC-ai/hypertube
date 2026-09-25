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

// Downloads every piece of `torrent` with a bounded pool of workers. Peers (peer-wire) and
// web-seeds (BEP19) share one source rotation, so both are used together rather than as a
// fallback chain. Pieces are written to the real files under `outputDir`, since a piece can
// straddle a file boundary in a multi-file torrent. A failing piece is requeued on another
// source, up to maxAttemptsPerPiece, before the download fails with a SwarmDownloadError.
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
  // Keep each distinct failure: the last error alone can hide the real cause across sources.
  const failureReasons = Array.from({ length: numPieces }, () => new Set());
  let completed = 0;
  let sourceCursor = 0;
  let failure = null;

  function nextSource() {
    const source = sources[sourceCursor % sources.length];
    sourceCursor += 1;

    return source;
  }

  // Memoize the open() *promise*: two workers asking for the same file in the same tick would
  // otherwise each open(path, 'w'), and the second would truncate what the first wrote.
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
          failureReasons[pieceIndex].add(err.message);

          if (attempts[pieceIndex] >= maxAttemptsPerPiece) {
            failure = new SwarmDownloadError(
              `Piece ${pieceIndex} failed after ${attempts[pieceIndex]} attempt(s) across the source pool: ${[...failureReasons[pieceIndex]].join(' | ')}`,
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

    // Zero-length files overlap no piece, so touch every file to make sure they all exist.
    await Promise.all(fileLayout.map((file) => handleFor(file)));

    return { outputDir, piecesDownloaded: completed, files: fileLayout.map((f) => f.path) };
  } finally {
    await Promise.all(
      [...fileHandlePromises.values()].map((promise) => promise.then((handle) => handle.close())),
    );
  }
}
