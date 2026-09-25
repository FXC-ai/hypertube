import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { parseTorrentFile } from '../../src/torrentFile.js';
import { computeFileLayout, computePieceRanges } from '../../src/torrentLayout.js';
import { downloadPieceFromWebSeed } from '../../src/webseed/downloadPieceFromWebSeed.js';

// Real network: archive.org's web-seed URL convention and Range handling, not a fake fetch.
test('downloads a real piece from archive.org via BEP19 web-seeding and verifies its hash', async () => {
  const torrentPath = fileURLToPath(
    new URL('../fixtures/1953_movie_trailers_starting_monday.archive.org.torrent', import.meta.url),
  );
  const torrent = parseTorrentFile(readFileSync(torrentPath));
  const fileLayout = computeFileLayout(torrent);
  const pieceRanges = computePieceRanges(torrent);

  const pieceIndex = 0;
  const { offset, length } = pieceRanges[pieceIndex];

  const piece = await downloadPieceFromWebSeed(
    'https://archive.org/download/',
    torrent,
    fileLayout,
    pieceIndex,
    offset,
    length,
    {
      pieceHash: torrent.pieces[pieceIndex],
    },
  );

  assert.equal(piece.length, length);
});
