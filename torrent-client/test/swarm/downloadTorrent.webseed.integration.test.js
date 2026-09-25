import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { downloadTorrent } from '../../src/swarm/downloadTorrent.js';
import { parseTorrentFile } from '../../src/torrentFile.js';
import { generatePeerId } from '../../src/trackers/peerId.js';
import { computeVideoSignature } from '../../src/videoSignature.js';

// Real network, zero peers: archive.org does not seed peer-to-peer, so an empty peer list is
// what a real download of this source looks like.
test(
  'downloads the complete archive.org torrent using only web-seeding, no peers',
  { timeout: 60000 },
  async () => {
    const torrentPath = fileURLToPath(
      new URL(
        '../fixtures/1953_movie_trailers_starting_monday.archive.org.torrent',
        import.meta.url,
      ),
    );
    const torrent = parseTorrentFile(readFileSync(torrentPath));
    assert.ok(
      torrent.urlList.length > 0,
      'fixture must have BEP19 url-list entries to run this test',
    );

    const outputDir = await mkdtemp(join(tmpdir(), 'archiveorg-webseed-'));

    try {
      const result = await downloadTorrent(torrent, [], {
        infoHash: Buffer.from(torrent.infoHash, 'hex'),
        peerId: generatePeerId(),
        outputDir,
        concurrency: 8,
        webSeedUrls: torrent.urlList,
        pieceTimeoutMs: 20000,
      });

      assert.equal(result.piecesDownloaded, torrent.pieces.length);

      const totalWrittenSize = torrent.files.reduce(
        (sum, file) => sum + statSync(join(outputDir, file.path)).size,
        0,
      );
      assert.equal(totalWrittenSize, torrent.totalLength);

      // Compare with the ground-truth signature fetched over plain HTTP: both download paths
      // must produce identical bytes.
      const signatureJsonPath = fileURLToPath(
        new URL(
          '../fixtures/reference-video/1953_movie_trailers_starting_monday.reference.signature.json',
          import.meta.url,
        ),
      );
      const referenceSignature = JSON.parse(readFileSync(signatureJsonPath, 'utf8')).signature;
      const downloadedVideo = await readFile(
        join(outputDir, '1953_Movie_Trailers_Starting_Monday_00_35_40_28_3mb.mp4'),
      );
      assert.deepEqual(computeVideoSignature(downloadedVideo), referenceSignature);
    } finally {
      await rm(outputDir, { recursive: true, force: true });
    }
  },
);
