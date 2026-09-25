import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { readFileSync, statSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { downloadPieceFromPeer } from '../../src/peer/downloadPiece.js';
import { downloadTorrent } from '../../src/swarm/downloadTorrent.js';
import { parseTorrentFile } from '../../src/torrentFile.js';
import { generatePeerId } from '../../src/trackers/peerId.js';
import { announceUdpTracker } from '../../src/trackers/udpTracker.js';

const execFileAsync = promisify(execFile);

// Full real download of the Sintel torrent (~129MB, 11 files, ~987 pieces). It is multi-file:
// piece 0 is subtitle text, and pieces straddle file boundaries, so the ffprobe check
// targets Sintel.mp4 under outputDir, not the raw piece stream.
//
// Most tracker-listed peers are unreachable, so first probe them (piece 0 from every
// candidate in parallel, short timeout) and keep only the ones that answered.
test(
  'downloads the complete Sintel torrent from the real swarm end to end',
  { timeout: 240000 },
  async () => {
    const torrentPath = fileURLToPath(
      new URL('../fixtures/sintel.webtorrent.io.torrent', import.meta.url),
    );
    const torrent = parseTorrentFile(readFileSync(torrentPath));
    const infoHash = Buffer.from(torrent.infoHash, 'hex');
    const peerId = generatePeerId();

    const announceResult = await announceUdpTracker(
      'udp://tracker.opentrackr.org:1337',
      {
        infoHash,
        peerId,
        port: 6881,
        left: torrent.totalLength,
        event: 'started',
      },
      { timeoutMs: 8000 },
    );
    assert.ok(announceResult.peers.length > 0, 'need peers from the tracker to run this test');

    const probeResults = await Promise.allSettled(
      announceResult.peers.map((peer) =>
        downloadPieceFromPeer(peer, {
          infoHash,
          peerId,
          pieceIndex: 0,
          pieceLength: torrent.pieceLength,
          pieceHash: torrent.pieces[0],
          connectTimeoutMs: 3000,
          overallTimeoutMs: 8000,
        }).then(() => peer),
      ),
    );
    const reachablePeers = probeResults.filter((r) => r.status === 'fulfilled').map((r) => r.value);
    assert.ok(reachablePeers.length > 0, 'need at least one reachable peer to run a full download');

    const outputDir = await mkdtemp(join(tmpdir(), 'sintel-swarm-'));

    try {
      const result = await downloadTorrent(torrent, reachablePeers, {
        infoHash,
        peerId,
        outputDir,
        concurrency: Math.min(40, reachablePeers.length * 4),
        pieceTimeoutMs: 15000,
        connectTimeoutMs: 5000,
        maxAttemptsPerPiece: Math.max(6, reachablePeers.length * 3),
      });

      assert.equal(result.piecesDownloaded, torrent.pieces.length);
      assert.ok(result.files.includes('Sintel.mp4'));

      const totalWrittenSize = torrent.files.reduce(
        (sum, file) => sum + statSync(join(outputDir, file.path)).size,
        0,
      );
      assert.equal(totalWrittenSize, torrent.totalLength);

      // ffprobe must be able to read the video stream back out of what we assembled.
      const videoPath = join(outputDir, 'Sintel.mp4');
      const { stdout } = await execFileAsync('ffprobe', [
        '-v',
        'error',
        '-show_entries',
        'stream=codec_type,codec_name',
        '-of',
        'json',
        videoPath,
      ]);
      const probed = JSON.parse(stdout);
      assert.ok(
        Array.isArray(probed.streams) && probed.streams.length > 0,
        'ffprobe should be able to open Sintel.mp4 and list its streams',
      );
      assert.ok(
        probed.streams.some((s) => s.codec_type === 'video'),
        'expected at least one decodable video stream',
      );
    } finally {
      await rm(outputDir, { recursive: true, force: true });
    }
  },
);
