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

// Real network, real web-seed servers, zero peers -- the literal #12
// acceptance criteria: "Téléchargement complet réussi d'un item archive.org
// réel n'ayant aucun pair P2P actif, uniquement via web-seeding". archive.org
// doesn't seed its own content peer-to-peer (documented since #7/#8's
// README notes), so passing an empty peer list here isn't a contrived
// edge case -- it's what a real download of this source looks like.
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

      // Closes the loop noted in #7/#9's README: compare the web-seed-assembled
      // video against the ground-truth signature fetched independently over
      // plain HTTP back in #7, proving both download paths produce identical
      // bytes.
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
