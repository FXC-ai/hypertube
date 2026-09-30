import { randomUUID } from 'node:crypto';
import { CancelledError } from '../cancelledError.js';
import {
  inspectTorrent as inspectParsedTorrent,
  resolveFileIndexes,
  validateFileIndexesShape,
} from '../fileSelection.js';
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

// In-memory jobs behind start/status/cancel. Every real dependency is injectable so tests
// need no network.
export function createDownloadManager({
  parseTorrentFileFn = parseTorrentFile,
  announceFn = defaultAnnounce,
  downloadTorrentFn = defaultDownloadTorrent,
  generatePeerIdFn = generatePeerId,
  fetchImpl = fetch,
  trackerPort = DEFAULT_TRACKER_PORT,
} = {}) {
  const jobs = new Map();

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
      status: 'downloading',
      downloadedBytes: 0,
      totalBytes: null,
      piecesCompleted: 0,
      totalPieces: null,
      infoHash: null,
      pieceLength: null,
      files: [],
      error: null,
      outputDir,
      controller: new AbortController(),
    };
    jobs.set(id, job);

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
          length: file.length,
          downloadedBytes: 0,
          complete: file.length === 0,
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

      const infoHash = Buffer.from(torrent.infoHash, 'hex');
      const peerId = generatePeerIdFn();
      const trackerUrls = flattenTrackerUrls(torrent);
      const announceResult = await announceFn(
        trackerUrls,
        {
          infoHash,
          peerId,
          port: trackerPort,
          left: job.totalBytes,
          event: 'started',
        },
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

      await downloadTorrentFn(torrent, peers, {
        infoHash,
        peerId,
        outputDir: job.outputDir,
        webSeedUrls,
        fileIndexes: selectedIndexes,
        signal: job.controller.signal,
        onProgress: ({ completed, pieceIndex }) => {
          job.piecesCompleted = completed;
          const { offset, length } = pieceRanges[pieceIndex];

          for (const overlap of computeOverlaps(selectedFiles, offset, length)) {
            const fileStatus = fileStatusByIndex.get(overlap.file.index);
            fileStatus.downloadedBytes += overlap.length;
            fileStatus.complete = fileStatus.downloadedBytes === fileStatus.length;
            job.downloadedBytes += overlap.length;
          }
        },
      });

      job.status = 'completed';
    } catch (err) {
      if (err instanceof CancelledError) {
        job.status = 'cancelled';
      } else {
        job.status = 'failed';
        job.error = err.message;
      }
    }
  }

  async function fetchTorrentBytes(torrentUrl) {
    let response;

    try {
      response = await fetchImpl(torrentUrl);
    } catch (err) {
      throw new TorrentFetchError(`Failed to fetch torrent from ${torrentUrl}: ${err.message}`);
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

    delete status.controller;

    return status;
  }

  function cancelDownload(id) {
    const job = jobs.get(id);

    if (!job) {
      return null;
    }

    if (job.status === 'downloading') {
      job.controller.abort();
    }

    return getStatus(id);
  }

  return { inspectTorrent, startDownload, getStatus, cancelDownload };
}

function requireTorrentSource({ torrentBytes, torrentUrl }) {
  if (!torrentBytes && !torrentUrl) {
    throw new DownloadManagerError('Provide either torrentBytes or torrentUrl');
  }
}
