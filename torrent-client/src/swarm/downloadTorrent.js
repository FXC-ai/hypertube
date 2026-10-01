import { open, mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { CancelledError } from '../cancelledError.js';
import { downloadPieceFromPeer } from '../peer/downloadPiece.js';
import {
  computeFileLayout,
  computePieceRanges,
  computeOverlaps,
  computeWantedPieces,
} from '../torrentLayout.js';
import { downloadPieceFromWebSeed } from '../webseed/downloadPieceFromWebSeed.js';
import { createSourcePool } from './sourcePool.js';

export class SwarmDownloadError extends Error {
  constructor(message) {
    super(message);
    this.name = 'SwarmDownloadError';
  }
}

const IDLE_POLL_MS = 200;
const MAX_PIECES_IN_STALL_MESSAGE = 3;

// Downloads the pieces of `torrent` covering `fileIndexes` (every file when omitted) with a
// bounded pool of workers. Only bytes belonging to those files are written: the unwanted part
// of a boundary piece is dropped, and unwanted files are never created. Pieces in `skipPieces`
// (already verified on disk by recheckPieces) are not fetched again, and existing files are
// written in place, never truncated.
//
// Peers (peer-wire) and web-seeds (BEP19) share one source pool (see sourcePool.js), so both
// are used together rather than as a fallback chain. A failing piece waits an exponential
// backoff, then goes to the source that failed it the least. When fewer than
// `minActiveSources` remain, `refreshSources` (a tracker re-announce) is called at most every
// `refreshIntervalMs`. There is no fixed budget per piece: the download fails only when no
// piece has completed for `stallTimeoutMs`, which covers a dead swarm and a piece that keeps
// failing everywhere alike.
export async function downloadTorrent(torrent, peers, options) {
  const {
    infoHash,
    peerId,
    outputDir,
    concurrency = 10,
    pieceTimeoutMs = 20000,
    connectTimeoutMs = 5000,
    webSeedUrls = [],
    fileIndexes,
    skipPieces = new Set(),
    refreshSources,
    minActiveSources = 3,
    refreshIntervalMs = 60000,
    stallTimeoutMs = 120000,
    backoffBaseMs = 1000,
    backoffMaxMs = 60000,
    sourceCooldownMs = 30000,
    onProgress,
    onSourcesChange,
    signal,
  } = options;

  const initialSources = [
    ...peers.map((peer) => ({ kind: 'peer', peer })),
    ...webSeedUrls.map((baseUrl) => ({ kind: 'webseed', baseUrl })),
  ];

  if (initialSources.length === 0) {
    throw new SwarmDownloadError('No candidate peers or web-seed URLs to download from');
  }

  const pool = createSourcePool(initialSources, { cooldownMs: sourceCooldownMs });
  const fileLayout = computeFileLayout(torrent);
  const pieceOffsets = computePieceRanges(torrent);
  const wantedFileIndexes = new Set(fileIndexes ?? fileLayout.map((file) => file.index));
  const wantedFiles = fileLayout.filter((file) => wantedFileIndexes.has(file.index));
  const pending = computeWantedPieces(fileLayout, pieceOffsets, wantedFileIndexes)
    .filter((pieceIndex) => !skipPieces.has(pieceIndex))
    .map((pieceIndex) => ({
      pieceIndex,
      readyAt: 0,
      attempts: 0,
      failuresBySource: new Map(),
      // Keep each distinct failure: the last error alone can hide the real cause.
      reasons: new Set(),
    }));
  const numPieces = pending.length;
  let completed = 0;
  let inFlight = 0;
  let failure = null;
  let lastProgressAt = Date.now();
  let lastRefreshAt = Date.now();
  let refreshing = null;

  function reportSources() {
    onSourcesChange?.(pool.counts());
  }

  function maybeRefreshSources() {
    if (
      !refreshSources ||
      refreshing ||
      pool.counts().active >= minActiveSources ||
      Date.now() - lastRefreshAt < refreshIntervalMs
    ) {
      return;
    }

    lastRefreshAt = Date.now();
    refreshing = refreshSources()
      .then((newPeers) => {
        if (pool.add(newPeers.map((peer) => ({ kind: 'peer', peer }))) > 0) {
          reportSources();
        }
      })
      .catch(() => {
        // a failed re-announce just means no new peers this time
      })
      .finally(() => {
        refreshing = null;
      });
  }

  function stallError() {
    const worst = [...pending]
      .sort((a, b) => b.attempts - a.attempts)
      .slice(0, MAX_PIECES_IN_STALL_MESSAGE)
      .map(
        (piece) =>
          `piece ${piece.pieceIndex} (${piece.attempts} attempt(s)): ${[...piece.reasons].join(' | ') || 'never attempted'}`,
      );
    const { active, dropped } = pool.counts();
    const droppedDetails = pool.describeDropped();

    return new SwarmDownloadError(
      `No piece completed for ${Math.round(stallTimeoutMs / 1000)}s (${completed}/${numPieces} downloaded, ${active} active source(s), ${dropped} dropped). ` +
        `Stuck: ${worst.join(' ; ') || 'none'}` +
        (droppedDetails.length > 0 ? `. Dropped sources: ${droppedDetails.join(' ; ')}` : ''),
    );
  }

  // How long an idle worker waits before looking again: until the next piece leaves its
  // backoff, a source comes back from cooldown, or a short poll while others are in flight.
  function idleDelay() {
    const now = Date.now();
    const wakeUps = [now + IDLE_POLL_MS, ...pending.map((piece) => piece.readyAt)];
    const reactivation = pool.nextReactivationAt();

    if (reactivation !== null) {
      wakeUps.push(reactivation);
    }

    return Math.max(10, Math.min(...wakeUps.filter((at) => at > now)) - now);
  }

  // Memoize the open() *promise*: two workers asking for the same file in the same tick would
  // otherwise each open it. Existing files are opened in place ('r+') so a resumed download
  // keeps the pieces already verified on disk.
  const fileHandlePromises = new Map();
  function handleFor(file) {
    let promise = fileHandlePromises.get(file.path);

    if (!promise) {
      promise = (async () => {
        const fullPath = join(outputDir, file.path);
        await mkdir(dirname(fullPath), { recursive: true });

        return open(fullPath, 'r+').catch((err) => {
          if (err.code === 'ENOENT') {
            return open(fullPath, 'w');
          }

          throw err;
        });
      })();
      fileHandlePromises.set(file.path, promise);
    }

    return promise;
  }

  async function fetchPiece(source, pieceIndex) {
    const { offset, length } = pieceOffsets[pieceIndex];

    return source.kind === 'webseed'
      ? downloadPieceFromWebSeed(source.baseUrl, torrent, fileLayout, pieceIndex, offset, length, {
          pieceHash: torrent.pieces[pieceIndex],
          timeoutMs: pieceTimeoutMs,
          signal,
        })
      : downloadPieceFromPeer(source.peer, {
          infoHash,
          peerId,
          pieceIndex,
          pieceLength: length,
          pieceHash: torrent.pieces[pieceIndex],
          connectTimeoutMs,
          overallTimeoutMs: pieceTimeoutMs,
          signal,
        });
  }

  async function worker() {
    for (;;) {
      if (failure || signal?.aborted || (pending.length === 0 && inFlight === 0)) {
        return;
      }

      if (Date.now() - lastProgressAt > stallTimeoutMs) {
        failure ??= stallError();

        return;
      }

      maybeRefreshSources();
      const now = Date.now();
      const readyIndex = pending.findIndex((piece) => piece.readyAt <= now);
      const source = readyIndex === -1 ? null : pool.pick(pending[readyIndex].failuresBySource);

      if (!source) {
        await sleep(idleDelay(), signal);
        continue;
      }

      const [piece] = pending.splice(readyIndex, 1);
      inFlight += 1;

      try {
        const buffer = await fetchPiece(source, piece.pieceIndex);
        const { offset } = pieceOffsets[piece.pieceIndex];

        for (const overlap of computeOverlaps(wantedFiles, offset, buffer.length)) {
          const handle = await handleFor(overlap.file);
          const data = buffer.subarray(overlap.rangeOffset, overlap.rangeOffset + overlap.length);
          await handle.write(data, 0, data.length, overlap.fileOffset);
        }

        pool.reportSuccess(source);
        completed += 1;
        lastProgressAt = Date.now();
        onProgress?.({ completed, total: numPieces, pieceIndex: piece.pieceIndex });
      } catch (err) {
        if (err instanceof CancelledError) {
          return; // cancelled, not failed -- don't requeue, don't count as an attempt
        }

        const before = pool.counts();
        pool.reportFailure(source, err);

        if (pool.counts().active !== before.active) {
          reportSources();
        }

        piece.attempts += 1;
        piece.failuresBySource.set(source.key, (piece.failuresBySource.get(source.key) ?? 0) + 1);
        piece.reasons.add(err.message);
        piece.readyAt =
          Date.now() + Math.min(backoffBaseMs * 2 ** (piece.attempts - 1), backoffMaxMs);
        pending.push(piece);
      } finally {
        inFlight -= 1;
      }
    }
  }

  try {
    reportSources();
    const workerCount = Math.min(concurrency, numPieces);
    await Promise.all(Array.from({ length: workerCount }, worker));

    if (signal?.aborted) {
      throw new CancelledError();
    }

    if (failure) {
      throw failure;
    }

    // Zero-length files overlap no piece, so touch every file to make sure they all exist.
    await Promise.all(wantedFiles.map((file) => handleFor(file)));

    return { outputDir, piecesDownloaded: completed, files: wantedFiles.map((f) => f.path) };
  } finally {
    await refreshing;
    await Promise.all(
      [...fileHandlePromises.values()].map((promise) => promise.then((handle) => handle.close())),
    );
  }
}

function sleep(ms, signal) {
  return new Promise((resolve) => {
    const timer = setTimeout(done, ms);

    function done() {
      clearTimeout(timer);
      signal?.removeEventListener('abort', done);
      resolve();
    }

    signal?.addEventListener('abort', done);
  });
}
