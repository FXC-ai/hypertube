import { open, mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { CancelledError } from '../cancelledError.js';
import { createPeerSession } from '../peer/peerSession.js';
import {
  computeFileLayout,
  computePieceRanges,
  computeOverlaps,
  computeWantedPieces,
} from '../torrentLayout.js';
import { downloadPiecesFromWebSeed } from '../webseed/downloadPieceFromWebSeed.js';
import { createSourcePool } from './sourcePool.js';

export class SwarmDownloadError extends Error {
  constructor(message) {
    super(message);
    this.name = 'SwarmDownloadError';
  }
}

const IDLE_POLL_MS = 200;
const MAX_PIECES_IN_STALL_MESSAGE = 3;
// Bytes a peer may have in flight: enough to keep a fast peer busy across its round trips.
const PEER_BYTES_IN_FLIGHT = 4 * 1024 * 1024;
const MAX_PIECES_PER_PEER = 32;
// End-game: once this few pieces are left, an idle worker also asks a second source for a piece
// already in flight, and the first valid copy wins.
const END_GAME_PIECES = 32;

// Downloads the pieces of `torrent` covering `fileIndexes` (every file when omitted). Only bytes
// belonging to those files are written: the unwanted part of a boundary piece is dropped, and
// unwanted files are never created. Pieces in `skipPieces` (already verified on disk by
// recheckPieces) are not fetched again, and existing files are written in place, never
// truncated.
//
// Peers (peer-wire) and web-seeds (BEP19) share one source pool (see sourcePool.js), so both are
// used together rather than as a fallback chain. Each peer gets one long-lived session (see
// peerSession.js) carrying several pieces at once; each web-seed serves runs of consecutive
// pieces in one ranged request. The pool caps how many pieces a source holds, so faster sources
// naturally take more. Up to `concurrency` pieces are in flight overall.
//
// A failing piece waits an exponential backoff, then goes to the source that failed it the
// least. When fewer than `minActiveSources` remain, or every `periodicRefreshMs`,
// `refreshSources` (a tracker re-announce) is called, at most every `refreshIntervalMs`. There is
// no fixed budget per piece: the download fails only when no piece has completed for
// `stallTimeoutMs`, which covers a dead swarm and a piece that keeps failing everywhere alike.
//
// `priorityOf(pieceIndex)` (lower first, default 0 for all) lets the caller reorder the queue
// while it runs, e.g. to fetch first the bytes ffmpeg is waiting for. Ties keep queue order.
// Pieces with a priority below `provenSourcesBelowPriority` go to sources that already
// delivered pieces rather than to the next untested one in the rotation.
//
// Stale pieces: archive.org rewrites some files of an item (its _meta.xml...) after the
// torrent was made, so a piece sharing bytes with such a file never matches its hash, whoever
// serves it. When a piece comes back with a wrong SHA-1 already seen for it, that is not the
// source's fault: it no longer counts toward banning the source. Once a web-seed has sent the
// same wrong copy `staleHashThreshold` times, and `acceptUnverifiedPiece(pieceIndex)` says the
// caller can check the files another way (archive.org publishes each file's SHA-1), the copy
// is written as is and reported with `unverified: true`; the result lists those pieces.
export async function downloadTorrent(torrent, peers, options) {
  const {
    infoHash,
    peerId,
    outputDir,
    concurrency = 64,
    pieceTimeoutMs = 20000,
    connectTimeoutMs = 5000,
    webSeedUrls = [],
    webSeedBatchBytes = 8 * 1024 * 1024,
    webSeedRequestsPerSeed = 3,
    fileIndexes,
    skipPieces = new Set(),
    refreshSources,
    minActiveSources = 3,
    refreshIntervalMs = 60000,
    periodicRefreshMs = 300000,
    stallTimeoutMs = 120000,
    backoffBaseMs = 1000,
    backoffMaxMs = 60000,
    sourceCooldownMs = 30000,
    onProgress,
    onSourcesChange,
    priorityOf = () => 0,
    provenSourcesBelowPriority = -Infinity,
    staleHashThreshold = 2,
    acceptUnverifiedPiece = () => false,
    signal,
  } = options;

  const initialSources = [
    ...peers.map((peer) => ({ kind: 'peer', peer })),
    ...webSeedUrls.map((baseUrl) => ({ kind: 'webseed', baseUrl })),
  ];

  if (initialSources.length === 0) {
    throw new SwarmDownloadError('No candidate peers or web-seed URLs to download from');
  }

  const piecesPerPeer = Math.min(
    MAX_PIECES_PER_PEER,
    Math.max(2, Math.ceil(PEER_BYTES_IN_FLIGHT / torrent.pieceLength)),
  );
  const pool = createSourcePool(initialSources, {
    cooldownMs: sourceCooldownMs,
    capacityOf: (source) => (source.kind === 'peer' ? piecesPerPeer : webSeedRequestsPerSeed),
  });
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
      badHashes: new Map(), // wrong SHA-1 received -> how many times
      done: false,
      holders: new Map(), // source key -> AbortController of each fetch of this piece
    }));
  const numPieces = pending.length;
  const inFlight = new Map(); // pieceIndex -> piece, while at least one source fetches it
  const sessions = new Map(); // peer key -> peer session
  const webSeedUrlCache = new Map();
  const unverifiedPieces = [];
  let completed = 0;
  let failure = null;
  let lastProgressAt = Date.now();
  let lastRefreshAt = Date.now();
  let refreshing = null;
  let waiters = [];

  // Wakes every idle worker: a slot was freed, a piece came back to the queue or completed.
  function wake() {
    const current = waiters;
    waiters = [];

    for (const resolve of current) {
      resolve();
    }
  }

  function waitForChange() {
    return new Promise((resolve) => {
      waiters.push(resolve);
      sleep(idleDelay(), signal).then(resolve);
    });
  }

  function reportSources() {
    onSourcesChange?.(pool.counts());
  }

  function maybeRefreshSources() {
    const now = Date.now();
    const due =
      pool.counts().active < minActiveSources
        ? now - lastRefreshAt >= refreshIntervalMs
        : now - lastRefreshAt >= periodicRefreshMs;

    if (!refreshSources || refreshing || !due) {
      return;
    }

    lastRefreshAt = now;
    refreshing = refreshSources()
      .then((newPeers) => {
        if (pool.add(newPeers.map((peer) => ({ kind: 'peer', peer }))) > 0) {
          reportSources();
          wake();
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
    const worst = [...pending, ...inFlight.values()]
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

  // How long an idle worker waits at most before looking again: until the next piece leaves its
  // backoff, a source comes back from cooldown, or a short poll.
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
    let promise = fileHandlePromises.get(file.fileName);

    if (!promise) {
      promise = (async () => {
        const fullPath = join(outputDir, file.fileName);
        await mkdir(dirname(fullPath), { recursive: true });

        return open(fullPath, 'r+').catch((err) => {
          if (err.code === 'ENOENT') {
            return open(fullPath, 'w');
          }

          throw err;
        });
      })();
      fileHandlePromises.set(file.fileName, promise);
    }

    return promise;
  }

  async function writePiece(pieceIndex, buffer) {
    const { offset } = pieceOffsets[pieceIndex];

    for (const overlap of computeOverlaps(wantedFiles, offset, buffer.length)) {
      const handle = await handleFor(overlap.file);
      const data = buffer.subarray(overlap.rangeOffset, overlap.rangeOffset + overlap.length);
      await handle.write(data, 0, data.length, overlap.fileOffset);
    }
  }

  // First valid copy of a piece: write it, stop the other sources still fetching it.
  async function complete(piece, buffer, { unverified = false } = {}) {
    if (piece.done) {
      return;
    }

    piece.done = true;

    for (const controller of piece.holders.values()) {
      controller.abort();
    }

    await writePiece(piece.pieceIndex, buffer);
    inFlight.delete(piece.pieceIndex);
    completed += 1;
    lastProgressAt = Date.now();
    onProgress?.({ completed, total: numPieces, pieceIndex: piece.pieceIndex, unverified });
    wake();
  }

  // Records a wrong copy of `piece`; true when that exact copy had already been received.
  function seenBefore(piece, err) {
    if (!err?.hashMismatch || !err.actualHash) {
      return false;
    }

    const times = piece.badHashes.get(err.actualHash) ?? 0;
    piece.badHashes.set(err.actualHash, times + 1);

    return times > 0;
  }

  // `source` stopped fetching `piece`. With `err`, that counts as a failure against the source
  // and the piece waits a backoff; without, it simply goes back to the queue (the rest of a
  // web-seed run that broke, or a copy another source beat). Either way the piece returns to the
  // queue only when no other source is still fetching it (end-game copies).
  function release(piece, source, err = null) {
    piece.holders.delete(source.key);

    if (piece.done || signal?.aborted) {
      return;
    }

    if (err && !(err instanceof CancelledError)) {
      const before = pool.counts().active;
      pool.reportFailure(source, err, { countHashFailure: !seenBefore(piece, err) });

      if (pool.counts().active !== before) {
        reportSources();
      }

      piece.attempts += 1;
      piece.failuresBySource.set(source.key, (piece.failuresBySource.get(source.key) ?? 0) + 1);
      piece.reasons.add(err.message);
      piece.readyAt =
        Date.now() + Math.min(backoffBaseMs * 2 ** (piece.attempts - 1), backoffMaxMs);
    }

    if (piece.holders.size === 0) {
      inFlight.delete(piece.pieceIndex);
      pending.push(piece);
    }

    wake();
  }

  function take(piece, source) {
    const index = pending.indexOf(piece);

    if (index !== -1) {
      pending.splice(index, 1);
    }

    const controller = new AbortController();
    piece.holders.set(source.key, controller);
    inFlight.set(piece.pieceIndex, piece);

    return controller;
  }

  function sessionFor(source) {
    const known = sessions.get(source.key);

    if (known && !known.isClosed()) {
      return known;
    }

    const session = createPeerSession(source.peer, {
      infoHash,
      peerId,
      connectTimeoutMs,
      maxOutstandingBlocks: Math.max(64, Math.ceil(PEER_BYTES_IN_FLIGHT / 16384)),
      onClose: () => {
        if (sessions.get(source.key) === session) {
          sessions.delete(source.key);
        }

        wake();
      },
    });
    sessions.set(source.key, session);

    return session;
  }

  async function fetchFromPeer(source, piece) {
    const controller = take(piece, source);
    const { length } = pieceOffsets[piece.pieceIndex];

    try {
      const session = sessionFor(source);
      await session.ready;
      const buffer = await session.requestPiece(
        piece.pieceIndex,
        length,
        torrent.pieces[piece.pieceIndex],
        {
          timeoutMs: pieceTimeoutMs,
          signal: signal ? AbortSignal.any([signal, controller.signal]) : controller.signal,
        },
      );
      piece.holders.delete(source.key);
      pool.reportSuccess(source);
      await complete(piece, buffer);
    } catch (err) {
      release(piece, source, err);
    }
  }

  // Consecutive queued pieces after `first`, ready now, up to webSeedBatchBytes.
  function batchFrom(first) {
    const batch = [first];
    let bytes = pieceOffsets[first.pieceIndex].length;
    const now = Date.now();

    for (;;) {
      const nextIndex = batch.at(-1).pieceIndex + 1;
      const next = pending.find((piece) => piece.pieceIndex === nextIndex);

      if (
        !next ||
        next.readyAt > now ||
        bytes + pieceOffsets[nextIndex].length > webSeedBatchBytes
      ) {
        return batch;
      }

      batch.push(next);
      bytes += pieceOffsets[nextIndex].length;
    }
  }

  async function fetchFromWebSeed(source, first) {
    const batch = batchFrom(first);
    const controller = new AbortController();

    for (const piece of batch) {
      take(piece, source);
      piece.holders.set(source.key, controller);
    }

    const byIndex = new Map(batch.map((piece) => [piece.pieceIndex, piece]));
    const settled = new Set();

    try {
      await downloadPiecesFromWebSeed(
        source.baseUrl,
        torrent,
        fileLayout,
        pieceOffsets,
        batch.map((piece) => piece.pieceIndex),
        {
          idleTimeoutMs: pieceTimeoutMs,
          urlCache: webSeedUrlCache,
          signal: signal ? AbortSignal.any([signal, controller.signal]) : controller.signal,
          onPiece: async (pieceIndex, buffer) => {
            const piece = byIndex.get(pieceIndex);
            settled.add(pieceIndex);
            piece.holders.delete(source.key);
            pool.reportSuccess(source);
            await complete(piece, buffer);
          },
          onBadPiece: async (pieceIndex, err, buffer) => {
            const piece = byIndex.get(pieceIndex);
            settled.add(pieceIndex);

            if (
              (piece.badHashes.get(err.actualHash) ?? 0) + 1 >= staleHashThreshold &&
              acceptUnverifiedPiece(pieceIndex)
            ) {
              seenBefore(piece, err);
              piece.holders.delete(source.key);
              piece.reasons.add(`${err.message} (kept unverified)`);
              unverifiedPieces.push(pieceIndex);
              await complete(piece, buffer, { unverified: true });

              return;
            }

            release(piece, source, err);
          },
        },
      );
    } catch (err) {
      // The first piece not received takes the blame; the rest simply go back to the queue.
      let blamed = false;

      for (const piece of batch) {
        if (!settled.has(piece.pieceIndex)) {
          release(piece, source, blamed ? null : err);
          blamed = true;
        }
      }
    }
  }

  // An in-flight piece another source could also fetch, when the end-game has started.
  function endGamePick() {
    if (numPieces - completed > END_GAME_PIECES || pending.some((p) => p.readyAt <= Date.now())) {
      return null;
    }

    for (const piece of inFlight.values()) {
      if (piece.done || piece.holders.size >= 2) {
        continue;
      }

      const avoid = new Map([...piece.holders.keys()].map((key) => [key, Infinity]));
      const source = pool.pick(avoid, { preferProven: true });

      if (source && !piece.holders.has(source.key)) {
        return { piece, source };
      }
    }

    return null;
  }

  async function worker() {
    for (;;) {
      if (failure || signal?.aborted || (pending.length === 0 && inFlight.size === 0)) {
        return;
      }

      if (Date.now() - lastProgressAt > stallTimeoutMs) {
        failure ??= stallError();
        wake();

        return;
      }

      maybeRefreshSources();
      const readyIndex = pickReadyPiece(pending, Date.now(), priorityOf);
      let piece = readyIndex === -1 ? null : pending[readyIndex];
      let source = piece
        ? pool.pick(piece.failuresBySource, {
            preferProven: priorityOf(piece.pieceIndex) < provenSourcesBelowPriority,
          })
        : null;

      if (!source) {
        ({ piece, source } = endGamePick() ?? {});
      }

      if (!source) {
        await waitForChange();
        continue;
      }

      pool.acquire(source);

      try {
        if (source.kind === 'webseed') {
          await fetchFromWebSeed(source, piece);
        } else {
          await fetchFromPeer(source, piece);
        }
      } finally {
        pool.release(source);
        wake();
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

    return {
      outputDir,
      piecesDownloaded: completed,
      files: wantedFiles.map((f) => f.fileName),
      unverifiedPieces,
    };
  } finally {
    for (const session of sessions.values()) {
      session.close();
    }

    await refreshing;
    await Promise.all(
      [...fileHandlePromises.values()].map((promise) => promise.then((handle) => handle.close())),
    );
  }
}

// Index in `pending` of the ready piece with the lowest priority value, or -1.
function pickReadyPiece(pending, now, priorityOf) {
  let best = -1;
  let bestPriority = Infinity;

  for (let i = 0; i < pending.length; i += 1) {
    if (pending[i].readyAt > now) {
      continue;
    }

    const priority = priorityOf(pending[i].pieceIndex);

    if (priority < bestPriority) {
      best = i;
      bestPriority = priority;
    }
  }

  return best;
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
