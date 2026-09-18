import { randomUUID } from 'node:crypto';
import { parseTorrentFile } from '../torrentFile.js';
import { announce as defaultAnnounce, flattenTrackerUrls } from '../trackers/announce.js';
import { generatePeerId } from '../trackers/peerId.js';
import { downloadTorrent as defaultDownloadTorrent } from '../swarm/downloadTorrent.js';
import { CancelledError } from '../cancelledError.js';

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
      if (job.controller.signal.aborted) throw new CancelledError();

      const torrent = parseTorrentFileFn(bytes);
      job.totalBytes = torrent.totalLength;
      job.totalPieces = torrent.pieces.length;

      const infoHash = Buffer.from(torrent.infoHash, 'hex');
      const peerId = generatePeerIdFn();
      const trackerUrls = flattenTrackerUrls(torrent);
      const announceResult = await announceFn(trackerUrls, {
        infoHash,
        peerId,
        port: trackerPort,
        left: torrent.totalLength,
        event: 'started',
      }, { fetchImpl });

      if (job.controller.signal.aborted) throw new CancelledError();
      if (!announceResult.peers || announceResult.peers.length === 0) {
        throw new DownloadManagerError('Tracker announce returned no peers');
      }

      await downloadTorrentFn(torrent, announceResult.peers, {
        infoHash,
        peerId,
        outputDir: job.outputDir,
        signal: job.controller.signal,
        onProgress: ({ completed, pieceIndex }) => {
          job.piecesCompleted = completed;
          job.downloadedBytes = Math.min(job.totalBytes, job.downloadedBytes + pieceByteLength(torrent, pieceIndex));
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
      throw new DownloadManagerError(`Failed to fetch torrent from ${torrentUrl}: HTTP ${response.status}`);
    }
    return Buffer.from(await response.arrayBuffer());
  }

  function getStatus(id) {
    const job = jobs.get(id);
    if (!job) return null;
    const { controller, ...status } = job;
    return status;
  }

  function cancelDownload(id) {
    const job = jobs.get(id);
    if (!job) return null;
    if (job.status === 'downloading') {
      job.controller.abort();
    }
    return getStatus(id);
  }

  return { startDownload, getStatus, cancelDownload };
}

function pieceByteLength(torrent, pieceIndex) {
  const isLast = pieceIndex === torrent.pieces.length - 1;
  return isLast ? torrent.totalLength - torrent.pieceLength * (torrent.pieces.length - 1) : torrent.pieceLength;
}
