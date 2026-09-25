import { randomUUID } from 'node:crypto';
import { CancelledError } from '../cancelledError.js';
import { downloadTorrent as defaultDownloadTorrent } from '../swarm/downloadTorrent.js';
import { parseTorrentFile } from '../torrentFile.js';
import { announce as defaultAnnounce, flattenTrackerUrls } from '../trackers/announce.js';
import { generatePeerId } from '../trackers/peerId.js';

export class DownloadManagerError extends Error {
  constructor(message) {
    super(message);
    this.name = 'DownloadManagerError';
  }
}

const DEFAULT_TRACKER_PORT = 6881;

// In-memory job tracker wrapping torrentFile parsing + tracker announce +
// swarm download behind a start/status/cancel surface, for the HTTP layer
// (#11) to expose. Every "real" dependency is injectable so tests don't need
// the network -- same pattern as fetchImpl in httpTracker.js and
// httpAnnouncer/udpAnnouncer in announce.js.
export function createDownloadManager({
  parseTorrentFileFn = parseTorrentFile,
  announceFn = defaultAnnounce,
  downloadTorrentFn = defaultDownloadTorrent,
  generatePeerIdFn = generatePeerId,
  fetchImpl = fetch,
  trackerPort = DEFAULT_TRACKER_PORT,
} = {}) {
  const jobs = new Map();

  async function startDownload({ torrentBytes, torrentUrl, outputDir }) {
    if (!torrentBytes && !torrentUrl) {
      throw new DownloadManagerError('Provide either torrentBytes or torrentUrl');
    }

    if (!outputDir) {
      throw new DownloadManagerError('outputDir is required');
    }

    const id = randomUUID();
    const job = {
      id,
      status: 'downloading',
      downloadedBytes: 0,
      totalBytes: null,
      piecesCompleted: 0,
      totalPieces: null,
      error: null,
      outputDir,
      controller: new AbortController(),
    };
    jobs.set(id, job);

    run(job, { torrentBytes, torrentUrl }).catch(() => {
      // run() always resolves the job's status itself; this catch only
      // exists so a genuinely unexpected throw can't become an unhandled
      // rejection.
    });

    return id;
  }

  async function run(job, { torrentBytes, torrentUrl }) {
    try {
      const bytes = torrentBytes ?? (await fetchTorrentBytes(torrentUrl));

      if (job.controller.signal.aborted) {
        throw new CancelledError();
      }

      const torrent = parseTorrentFileFn(bytes);
      job.totalBytes = torrent.totalLength;
      job.totalPieces = torrent.pieces.length;

      const infoHash = Buffer.from(torrent.infoHash, 'hex');
      const peerId = generatePeerIdFn();
      const trackerUrls = flattenTrackerUrls(torrent);
      const announceResult = await announceFn(
        trackerUrls,
        {
          infoHash,
          peerId,
          port: trackerPort,
          left: torrent.totalLength,
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
        // No P2P peers is expected and fine for sources like archive.org
        // (see #12) as long as the torrent provides BEP19 web-seed URLs --
        // only genuinely fail when there's no way to get bytes at all.
        throw new DownloadManagerError(
          'Tracker announce returned no peers and the torrent has no web-seed URLs',
        );
      }

      await downloadTorrentFn(torrent, peers, {
        infoHash,
        peerId,
        outputDir: job.outputDir,
        webSeedUrls,
        signal: job.controller.signal,
        onProgress: ({ completed, pieceIndex }) => {
          job.piecesCompleted = completed;
          job.downloadedBytes = Math.min(
            job.totalBytes,
            job.downloadedBytes + pieceByteLength(torrent, pieceIndex),
          );
        },
      });

      job.status = 'completed';
      job.downloadedBytes = job.totalBytes;
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
      throw new DownloadManagerError(`Failed to fetch torrent from ${torrentUrl}: ${err.message}`);
    }

    if (!response.ok) {
      throw new DownloadManagerError(
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

  return { startDownload, getStatus, cancelDownload };
}

function pieceByteLength(torrent, pieceIndex) {
  const isLast = pieceIndex === torrent.pieces.length - 1;

  return isLast
    ? torrent.totalLength - torrent.pieceLength * (torrent.pieces.length - 1)
    : torrent.pieceLength;
}
