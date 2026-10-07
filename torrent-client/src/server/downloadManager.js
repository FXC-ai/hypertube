import { randomUUID } from 'node:crypto';
import { copyFile, link } from 'node:fs/promises';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { CancelledError } from '../cancelledError.js';
import {
  inspectTorrent as inspectParsedTorrent,
  resolveFileIndexes,
  validateFileIndexesShape,
} from '../fileSelection.js';
import { createPieceMap } from '../stream/pieceMap.js';
import { parseTorrentFile } from '../torrentFile.js';
import { computeFileLayout, computePieceRanges, computeWantedPieces } from '../torrentLayout.js';
import {
  createTransmissionClient,
  LOCAL_ERROR,
  pieceStatesFromBitfield,
  STATUS,
} from '../transmission/transmissionClient.js';

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

const INFO_HASH_PATTERN = /^[0-9a-f]{40}$/i;

// In-memory jobs behind start/status/cancel. Transmission downloads; this keeps, per job,
// the files Laravel chose, where they are on disk and which of their bytes are verified.
// Every dependency is injectable so tests need neither network nor Transmission.
export function createDownloadManager({
  transmission = createTransmissionClient(),
  parseTorrentFileFn = parseTorrentFile,
  fetchImpl = fetch,
  pollIntervalMs = 1000,
  stallTimeoutMs = 10 * 60 * 1000,
} = {}) {
  const jobs = new Map();

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
      pieces: null,
      error: null,
      outputDir,
      controller: new AbortController(),
      layout: [],
      pieceMap: null,
    };
    jobs.set(id, job);

    run(job, { torrentBytes, torrentUrl, fileIndexes, expectedInfoHash }).catch(() => {
      // run() records its own failures; this only prevents an unhandled rejection.
    });

    return id;
  }

  async function run(job, { torrentBytes, torrentUrl, fileIndexes, expectedInfoHash }) {
    const { signal } = job.controller;
    let added = false;

    try {
      const bytes = torrentBytes ?? (await fetchTorrentBytes(torrentUrl));
      throwIfAborted(signal);
      const torrent = parseTorrentFileFn(bytes);
      const hash = torrent.infoHash.toLowerCase();

      if (expectedInfoHash && expectedInfoHash.toLowerCase() !== hash) {
        throw new DownloadManagerError(
          `The .torrent info-hash ${torrent.infoHash} does not match expectedInfoHash ${expectedInfoHash}: it changed since inspection`,
        );
      }

      const selectedIndexes = resolveFileIndexes(torrent, fileIndexes);
      const selected = new Set(selectedIndexes);
      const fileLayout = computeFileLayout(torrent);
      const wantedPieces = computeWantedPieces(fileLayout, computePieceRanges(torrent), selected);
      const pieceMapFrom = (states) =>
        createPieceMap({
          pieceLength: torrent.pieceLength,
          totalLength: torrent.totalLength,
          states,
        });

      job.layout = fileLayout.filter((file) => selected.has(file.index));
      job.infoHash = torrent.infoHash;
      job.pieceLength = torrent.pieceLength;
      job.totalBytes = job.layout.reduce((sum, file) => sum + file.length, 0);
      job.totalPieces = wantedPieces.length;
      job.pieceMap = pieceMapFrom([]);
      refreshFiles(job);

      const existing = await transmission.getTorrent(hash);

      if (existing && !samePath(existing.downloadDir, job.outputDir)) {
        throw new DownloadManagerError(
          `Transmission already downloads this torrent into ${existing.downloadDir}, not ${job.outputDir}`,
        );
      }

      if (!existing) {
        await transmission.addTorrent({ bytes, downloadDir: job.outputDir });
      }

      added = true;
      await transmission.selectFiles(hash, {
        wanted: selectedIndexes,
        unwanted: fileLayout.map((file) => file.index).filter((index) => !selected.has(index)),
      });

      // Transmission keeps the torrent's folders (<name>/<path>): read the files there while
      // they download, link them flat into outputDir once complete.
      const onDisk = await transmission.files(hash);

      for (const file of job.layout) {
        file.diskPath = onDisk[file.index]?.name ?? file.fileName;
      }

      await transmission.start(hash);

      let lastProgressAt = Date.now();

      for (;;) {
        const before = job.piecesCompleted;
        const info = await transmission.getTorrent(hash);

        if (!info) {
          throw new DownloadManagerError('The torrent was removed from Transmission');
        }

        if (info.error === LOCAL_ERROR) {
          throw new DownloadManagerError(`Transmission: ${info.errorString}`);
        }

        job.pieceMap = pieceMapFrom(pieceStatesFromBitfield(info.pieces, torrent.pieces.length));
        job.piecesCompleted = job.pieceMap.countDone(wantedPieces);
        refreshFiles(job);

        if (job.piecesCompleted === job.totalPieces) {
          await linkFlat(job);
          job.status = 'completed';

          return;
        }

        const checking = info.status === STATUS.checkWait || info.status === STATUS.checking;
        job.status = checking ? 'checking' : 'downloading';

        if (checking || job.piecesCompleted > before) {
          lastProgressAt = Date.now();
        } else if (Date.now() - lastProgressAt > stallTimeoutMs) {
          throw new DownloadManagerError(
            `No piece completed for ${Math.round(stallTimeoutMs / 1000)} s (${info.peersConnected ?? 0} peer(s), ${info.webseedsSendingToUs ?? 0} web-seed(s) sending${info.errorString ? `, ${info.errorString}` : ''})`,
          );
        }

        await sleep(pollIntervalMs, undefined, { signal }).catch(() => {});
        throwIfAborted(signal);
      }
    } catch (err) {
      if (err instanceof CancelledError || signal.aborted) {
        job.status = 'cancelled';
      } else {
        job.status = 'failed';
        job.error = err.message;
      }

      // Transmission forgets it, the files stay: a new POST on the same outputDir resumes.
      if (added) {
        await transmission.remove(job.infoHash.toLowerCase()).catch(() => {});
      }
    }
  }

  // Laravel reads movies/{id}/{fileName}. A hard link is the same file under a second name:
  // no copy, and Transmission keeps seeding from its own path. Copied if links are refused.
  async function linkFlat(job) {
    for (const file of job.layout) {
      if (file.length === 0 || file.diskPath === file.fileName) {
        continue;
      }

      const from = join(job.outputDir, file.diskPath);
      const to = join(job.outputDir, file.fileName);

      try {
        await link(from, to);
      } catch (err) {
        if (err.code !== 'EEXIST') {
          await copyFile(from, to);
        }
      }
    }
  }

  function refreshFiles(job) {
    job.files = job.layout.map((file) => {
      const downloadedBytes = job.pieceMap.downloadedBytes(file);

      return {
        index: file.index,
        path: file.path,
        fileName: file.fileName,
        length: file.length,
        downloadedBytes,
        contiguousBytesFromStart: job.pieceMap.contiguousBytesFromStart(file),
        availableRanges: job.pieceMap.fileRanges(file),
        complete: downloadedBytes === file.length,
      };
    });
    job.downloadedBytes = job.files.reduce((sum, file) => sum + file.downloadedBytes, 0);
    job.pieces = job.pieceMap.bitfieldBase64();
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
    delete status.layout;
    delete status.pieceMap;

    return status;
  }

  // For the stream endpoint: the live job, the file entry and where it is on disk.
  function getStreamSource(id, fileIndex) {
    const job = jobs.get(id);

    if (!job) {
      return null;
    }

    return { job, file: job.layout.find((file) => file.index === fileIndex) ?? null };
  }

  function cancelDownload(id) {
    const job = jobs.get(id);

    if (!job) {
      return null;
    }

    if (job.status === 'downloading' || job.status === 'checking') {
      job.controller.abort();
    }

    return getStatus(id);
  }

  return { inspectTorrent, startDownload, getStatus, getStreamSource, cancelDownload };
}

function requireTorrentSource({ torrentBytes, torrentUrl }) {
  if (!torrentBytes && !torrentUrl) {
    throw new DownloadManagerError('Provide either torrentBytes or torrentUrl');
  }
}

function throwIfAborted(signal) {
  if (signal.aborted) {
    throw new CancelledError();
  }
}

function samePath(a, b) {
  return a.replace(/\/+$/, '') === b.replace(/\/+$/, '');
}
