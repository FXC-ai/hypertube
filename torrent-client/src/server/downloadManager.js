import { randomUUID } from 'node:crypto';
import { open } from 'node:fs/promises';
import { join } from 'node:path';
import { archiveItemOf, fetchArchiveFileHashes, sha1OfFile } from '../archiveOrg.js';
import { CancelledError } from '../cancelledError.js';
import {
  classifyFile,
  inspectTorrent as inspectParsedTorrent,
  resolveFileIndexes,
  validateFileIndexesShape,
} from '../fileSelection.js';
import { recheckPieces } from '../recheck.js';
import { createPieceAvailability, PRIORITY } from '../stream/pieceAvailability.js';
import { downloadTorrent as defaultDownloadTorrent } from '../swarm/downloadTorrent.js';
import { parseTorrentFile } from '../torrentFile.js';
import {
  computeFileLayout,
  computeOverlaps,
  computePieceRanges,
  computeWantedPieces,
} from '../torrentLayout.js';
import { announce as defaultAnnounce, flattenTrackerUrls } from '../trackers/announce.js';
import { generatePeerId } from '../trackers/peerId.js';
import { detectContainerFormat } from '../videoSignature.js';
import { describeFetchError } from '../webseed/downloadPieceFromWebSeed.js';

export class DownloadManagerError extends Error {
  constructor(message) {
    super(message);
    this.name = 'DownloadManagerError';
  }
}

// The .torrent itself could not be fetched: an upstream failure, not a bad request.
export class TorrentFetchError extends DownloadManagerError {
  constructor(message) {
    super(message);
    this.name = 'TorrentFetchError';
  }
}

const DEFAULT_TRACKER_PORT = 6881;
const INFO_HASH_PATTERN = /^[0-9a-f]{40}$/i;
const CONTAINER_SNIFF_BYTES = 12;
const ENDED_STATUSES = new Set(['failed', 'cancelled']);

// In-memory jobs behind start/status/cancel. Every real dependency is injectable so tests
// need no network.
export function createDownloadManager({
  parseTorrentFileFn = parseTorrentFile,
  announceFn = defaultAnnounce,
  downloadTorrentFn = defaultDownloadTorrent,
  recheckPiecesFn = recheckPieces,
  generatePeerIdFn = generatePeerId,
  fetchImpl = fetch,
  trackerPort = DEFAULT_TRACKER_PORT,
  torrentFetchAttempts = 3,
  torrentFetchRetryDelayMs = 1000,
  fetchArchiveFileHashesFn = (item) => fetchArchiveFileHashes(item, { fetchImpl }),
} = {}) {
  const jobs = new Map();
  // Per job, what the streaming endpoint needs once the .torrent is parsed (not in the status).
  const streamSources = new Map();

  // Synchronous for the caller: returns the file list so it can pick fileIndexes.
  async function inspectTorrent({ torrentBytes, torrentUrl }) {
    requireTorrentSource({ torrentBytes, torrentUrl });
    const bytes = torrentBytes ?? (await fetchTorrentBytes(torrentUrl));

    return inspectParsedTorrent(parseTorrentFileFn(bytes));
  }

  async function startDownload({
    torrentBytes,
    torrentUrl,
    outputDir,
    fileIndexes,
    expectedInfoHash,
  }) {
    requireTorrentSource({ torrentBytes, torrentUrl });

    if (!outputDir) {
      throw new DownloadManagerError('outputDir is required');
    }

    try {
      validateFileIndexesShape(fileIndexes);
    } catch (err) {
      throw new DownloadManagerError(err.message);
    }

    if (expectedInfoHash !== undefined && !INFO_HASH_PATTERN.test(String(expectedInfoHash))) {
      throw new DownloadManagerError('expectedInfoHash must be a 40-character hex string');
    }

    const id = randomUUID();
    const job = {
      id,
      // "checking" covers fetching the .torrent and re-verifying files already on disk
      status: 'checking',
      downloadedBytes: 0,
      totalBytes: null,
      piecesCompleted: 0,
      totalPieces: null,
      infoHash: null,
      pieceLength: null,
      files: [],
      sources: null,
      error: null,
      outputDir,
      controller: new AbortController(),
    };
    jobs.set(id, job);
    streamSources.set(id, {
      ready: deferred(),
      availability: null,
      files: null,
      pieceLength: null,
    });

    run(job, { torrentBytes, torrentUrl, fileIndexes, expectedInfoHash }).catch(() => {
      // run() records its own failures; this only prevents an unhandled rejection.
    });

    return id;
  }

  async function run(job, { torrentBytes, torrentUrl, fileIndexes, expectedInfoHash }) {
    try {
      const bytes = torrentBytes ?? (await fetchTorrentBytes(torrentUrl));

      if (job.controller.signal.aborted) {
        throw new CancelledError();
      }

      const torrent = parseTorrentFileFn(bytes);

      if (expectedInfoHash && expectedInfoHash.toLowerCase() !== torrent.infoHash.toLowerCase()) {
        throw new DownloadManagerError(
          `The .torrent info-hash ${torrent.infoHash} does not match expectedInfoHash ${expectedInfoHash}: it changed since inspection`,
        );
      }

      const selectedIndexes = resolveFileIndexes(torrent, fileIndexes);
      const fileLayout = computeFileLayout(torrent);
      const pieceRanges = computePieceRanges(torrent);
      const selectedFiles = fileLayout.filter((file) => selectedIndexes.includes(file.index));
      const fileStatusByIndex = new Map();

      job.infoHash = torrent.infoHash;
      job.pieceLength = torrent.pieceLength;
      job.files = selectedFiles.map((file) => {
        const fileStatus = {
          index: file.index,
          path: file.path,
          fileName: file.fileName,
          length: file.length,
          downloadedBytes: 0,
          complete: file.length === 0,
          detectedContainer: null,
        };
        fileStatusByIndex.set(file.index, fileStatus);

        return fileStatus;
      });
      job.totalBytes = selectedFiles.reduce((sum, file) => sum + file.length, 0);
      job.totalPieces = computeWantedPieces(
        fileLayout,
        pieceRanges,
        new Set(selectedIndexes),
      ).length;

      const availability = createPieceAvailability({
        pieceCount: torrent.pieces.length,
        pieceLength: torrent.pieceLength,
        totalLength: torrent.totalLength,
      });
      const streamSource = streamSources.get(job.id);
      Object.assign(streamSource, {
        availability,
        files: selectedFiles,
        pieceLength: torrent.pieceLength,
      });
      // ffprobe needs the start of the main video and often its end (moov, Cues): fetch them
      // before anything else that nobody is waiting for.
      const mainVideo = selectedFiles
        .filter((file) => classifyFile(file.path).kind === 'video' && file.length > 0)
        .sort((a, b) => b.length - a.length)[0];

      if (mainVideo) {
        availability.setBoosted([
          Math.floor(mainVideo.torrentOffset / torrent.pieceLength),
          Math.floor((mainVideo.torrentOffset + mainVideo.length - 1) / torrent.pieceLength),
        ]);
      }

      streamSource.ready.resolve();

      // An unverified piece (see "Stale pieces" in downloadTorrent.js) counts as downloaded but
      // is not served to the stream until its whole file has been checked.
      function countPiece(pieceIndex, { unverified = false } = {}) {
        const { offset, length } = pieceRanges[pieceIndex];
        job.piecesCompleted += 1;

        if (!unverified) {
          availability.mark(pieceIndex);
          detectContainers(job, selectedFiles, availability);
        }

        for (const overlap of computeOverlaps(selectedFiles, offset, length)) {
          const fileStatus = fileStatusByIndex.get(overlap.file.index);
          fileStatus.downloadedBytes += overlap.length;
          fileStatus.complete = fileStatus.downloadedBytes === fileStatus.length;
          job.downloadedBytes += overlap.length;
        }
      }

      // Resume: pieces already valid on disk (previous attempt, restart) are not fetched again.
      const piecesOnDisk = await recheckPiecesFn(torrent, {
        outputDir: job.outputDir,
        fileIndexes: selectedIndexes,
        signal: job.controller.signal,
        onPiece: ({ pieceIndex, valid }) => valid && countPiece(pieceIndex),
      });

      if (piecesOnDisk.size === job.totalPieces) {
        job.status = 'completed';

        return;
      }

      job.status = 'downloading';

      const infoHash = Buffer.from(torrent.infoHash, 'hex');
      const peerId = generatePeerIdFn();
      const trackerUrls = flattenTrackerUrls(torrent);
      const announceParams = () => ({
        infoHash,
        peerId,
        port: trackerPort,
        left: job.totalBytes - job.downloadedBytes,
      });
      const announceResult = await announceFn(
        trackerUrls,
        { ...announceParams(), event: 'started' },
        { fetchImpl },
      );

      if (job.controller.signal.aborted) {
        throw new CancelledError();
      }

      const peers = announceResult.peers ?? [];
      const webSeedUrls = torrent.urlList ?? [];

      if (peers.length === 0 && webSeedUrls.length === 0) {
        // No peers is fine (e.g. archive.org) as long as web-seed URLs exist.
        throw new DownloadManagerError(
          'Tracker announce returned no peers and the torrent has no web-seed URLs',
        );
      }

      const archiveItem = archiveItemOf(torrent);
      const result = await downloadTorrentFn(torrent, peers, {
        infoHash,
        peerId,
        outputDir: job.outputDir,
        webSeedUrls,
        fileIndexes: selectedIndexes,
        skipPieces: piecesOnDisk,
        refreshSources: async () =>
          (await announceFn(trackerUrls, announceParams(), { fetchImpl })).peers ?? [],
        signal: job.controller.signal,
        priorityOf: availability.rank,
        provenSourcesBelowPriority: PRIORITY.NORMAL,
        onSourcesChange: (counts) => {
          job.sources = counts;
        },
        acceptUnverifiedPiece: () => archiveItem !== null,
        onProgress: ({ pieceIndex, unverified }) => countPiece(pieceIndex, { unverified }),
      });

      if (result?.unverifiedPieces?.length > 0) {
        job.status = 'checking';
        await verifyWholeFiles(job, {
          archiveItem,
          selectedFiles,
          pieceRanges,
          unverifiedPieces: result.unverifiedPieces,
        });
        result.unverifiedPieces.forEach((pieceIndex) => availability.mark(pieceIndex));
        detectContainers(job, selectedFiles, availability);
      }

      job.status = 'completed';
    } catch (err) {
      if (err instanceof CancelledError) {
        job.status = 'cancelled';
      } else {
        job.status = 'failed';
        job.error = err.message;
      }
    } finally {
      streamSources.get(job.id).ready.resolve();
    }
  }

  // Every chosen file touched by an unverified piece must match the SHA-1 archive.org publishes
  // for it; otherwise the job fails rather than hand over a file nobody could check.
  async function verifyWholeFiles(
    job,
    { archiveItem, selectedFiles, pieceRanges, unverifiedPieces },
  ) {
    const hashes = await fetchArchiveFileHashesFn(archiveItem);
    const touched = new Set();

    for (const pieceIndex of unverifiedPieces) {
      const { offset, length } = pieceRanges[pieceIndex];

      for (const overlap of computeOverlaps(selectedFiles, offset, length)) {
        touched.add(overlap.file);
      }
    }

    for (const file of touched) {
      const expected = hashes.get(file.path);
      const pieces = unverifiedPieces.join(', ');

      if (!expected) {
        throw new DownloadManagerError(
          `Piece(s) ${pieces} never matched their hash and archive.org publishes no SHA-1 for ${file.path}: cannot check it`,
        );
      }

      const actual = await sha1OfFile(join(job.outputDir, file.fileName));

      if (actual !== expected) {
        throw new DownloadManagerError(
          `Piece(s) ${pieces} never matched their hash, and ${file.path} does not match the SHA-1 archive.org publishes (expected ${expected}, got ${actual})`,
        );
      }
    }
  }

  // Container sniffed from the first bytes on disk, once they are verified. No ffmpeg: the
  // signature is enough to tell a real MP4/MKV from a fake file early.
  function detectContainers(job, selectedFiles, availability) {
    for (const fileStatus of job.files) {
      const file = selectedFiles.find((f) => f.index === fileStatus.index);
      const needed = Math.min(CONTAINER_SNIFF_BYTES, file.length);

      if (fileStatus.detectedContainer !== null || fileStatus.sniffing || needed === 0) {
        continue;
      }

      if (availability.contiguousBytesFromStart(file) < needed) {
        continue;
      }

      fileStatus.sniffing = true;
      readHead(join(job.outputDir, file.fileName), needed)
        .then((head) => {
          const format = detectContainerFormat(head);
          fileStatus.detectedContainer = format === 'webm/mkv' ? 'matroska' : format;
        })
        .catch(() => {
          fileStatus.detectedContainer = 'unknown';
        });
    }
  }

  // Everything the streaming endpoint needs for one file of one job, waiting up to
  // `stallTimeoutMs` for the .torrent to be parsed. One of:
  // { kind: 'notFound' } | { kind: 'gone', status, error } | { kind: 'stalled' }
  // | { kind: 'ready', source }
  async function openStream(id, fileIndex, { stallTimeoutMs }) {
    const job = jobs.get(id);
    const streamSource = streamSources.get(id);

    if (!job) {
      return { kind: 'notFound' };
    }

    if (!streamSource.availability) {
      const parsed = await Promise.race([
        streamSource.ready.promise.then(() => true),
        new Promise((resolve) => setTimeout(() => resolve(false), stallTimeoutMs)),
      ]);

      if (!parsed) {
        return { kind: 'stalled' };
      }
    }

    if (!streamSource.availability) {
      return { kind: 'gone', status: job.status, error: job.error };
    }

    const file = streamSource.files.find((f) => f.index === fileIndex);

    if (!file) {
      return { kind: 'notFound' };
    }

    return {
      kind: 'ready',
      source: {
        file,
        path: join(job.outputDir, file.fileName),
        pieceLength: streamSource.pieceLength,
        availability: streamSource.availability,
        endedState: () =>
          ENDED_STATUSES.has(job.status) ? { status: job.status, error: job.error } : null,
      },
    };
  }

  // A network error is retried (a dropped connection is common, and failing here fails the whole
  // job); an HTTP error is the server's answer and is not.
  async function fetchTorrentBytes(torrentUrl) {
    let response;

    for (let attempt = 1; !response; attempt += 1) {
      try {
        response = await fetchImpl(torrentUrl);
      } catch (err) {
        if (attempt >= torrentFetchAttempts) {
          throw new TorrentFetchError(
            `Failed to fetch torrent from ${torrentUrl} after ${attempt} attempt(s): ${describeFetchError(err)}`,
          );
        }

        await new Promise((resolve) => setTimeout(resolve, torrentFetchRetryDelayMs * attempt));
      }
    }

    if (!response.ok) {
      throw new TorrentFetchError(
        `Failed to fetch torrent from ${torrentUrl}: HTTP ${response.status}`,
      );
    }

    return Buffer.from(await response.arrayBuffer());
  }

  function getStatus(id) {
    const job = jobs.get(id);

    if (!job) {
      return null;
    }

    const status = { ...job };
    const { availability, files } = streamSources.get(id);

    delete status.controller;
    status.files = job.files.map((fileStatus) => {
      const publicFields = { ...fileStatus };
      delete publicFields.sniffing;
      const file = files?.find((f) => f.index === fileStatus.index);

      return {
        ...publicFields,
        contiguousBytesFromStart: availability ? availability.contiguousBytesFromStart(file) : 0,
        availableRanges: availability ? availability.fileRanges(file) : [],
      };
    });
    status.pieces = availability ? availability.bitfieldBase64() : null;

    return status;
  }

  function cancelDownload(id) {
    const job = jobs.get(id);

    if (!job) {
      return null;
    }

    if (job.status === 'checking' || job.status === 'downloading') {
      job.controller.abort();
    }

    return getStatus(id);
  }

  return { inspectTorrent, startDownload, getStatus, cancelDownload, openStream };
}

function deferred() {
  let resolve;
  const promise = new Promise((res) => {
    resolve = res;
  });

  return { promise, resolve };
}

async function readHead(path, length) {
  const handle = await open(path, 'r');

  try {
    const head = Buffer.alloc(length);
    await handle.read(head, 0, length, 0);

    return head;
  } finally {
    await handle.close();
  }
}

function requireTorrentSource({ torrentBytes, torrentUrl }) {
  if (!torrentBytes && !torrentUrl) {
    throw new DownloadManagerError('Provide either torrentBytes or torrentUrl');
  }
}
