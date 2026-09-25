import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { downloadPieceFromPeer } from '../../src/peer/downloadPiece.js';
import { parseTorrentFile } from '../../src/torrentFile.js';
import { generatePeerId } from '../../src/trackers/peerId.js';
import { announceUdpTracker } from '../../src/trackers/udpTracker.js';

// Real network, real swarm, same reference torrent as announce.integration.test.js.
// Most BitTorrent peers listed by a tracker are unreachable at any given
// moment (NAT, offline, firewalled) -- normal for P2P, not a bug. A real
// client tries many candidates and keeps whichever connects first, so this
// test does the same with Promise.any() rather than picking one peer and
// hoping it answers.
test(
  'downloads a real piece from a real peer in the Sintel swarm and verifies its hash',
  { timeout: 45000 },
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

    assert.ok(
      announceResult.peers.length > 0,
      'need at least one peer from the tracker to run this test',
    );

    const pieceIndex = 0;
    const pieceHash = torrent.pieces[pieceIndex];
    const candidates = announceResult.peers.slice(0, 30);

    const attempts = candidates.map((peer) =>
      downloadPieceFromPeer(peer, {
        infoHash,
        peerId,
        pieceIndex,
        pieceLength: torrent.pieceLength,
        pieceHash,
        connectTimeoutMs: 4000,
        overallTimeoutMs: 15000,
      }),
    );

    const piece = await Promise.any(attempts);

    assert.equal(piece.length, torrent.pieceLength);
    assert.equal(createHash('sha1').update(piece).digest('hex'), pieceHash);
  },
);
