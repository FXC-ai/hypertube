import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { parseTorrentFile } from '../src/torrentFile.js';

// Real short torrent from archive.org (~3MB). The expected info-hash comes from archive.org's
// own item metadata, independent of this parser.
const FIXTURE_PATH = fileURLToPath(
  new URL('./fixtures/1953_movie_trailers_starting_monday.archive.org.torrent', import.meta.url),
);
const KNOWN_INFO_HASH = '2e25fe743c733513a548bfdf8db48fe8f2c5f590';

test('parses a real archive.org reference .torrent and matches its known info-hash', () => {
  const buffer = readFileSync(FIXTURE_PATH);
  const parsed = parseTorrentFile(buffer);

  assert.equal(parsed.infoHash, KNOWN_INFO_HASH);
  assert.equal(parsed.announce, 'http://bt1.archive.org:6969/announce');
  assert.deepEqual(parsed.announceList, [
    ['http://bt1.archive.org:6969/announce'],
    ['http://bt2.archive.org:6969/announce'],
  ]);
  assert.equal(parsed.name, '1953_Movie_Trailers_Starting_Monday');
  assert.equal(parsed.pieceLength, 524288);
  assert.equal(parsed.pieces.length, 6);
  assert.ok(parsed.pieces.every((hash) => /^[0-9a-f]{40}$/.test(hash)));
  assert.equal(parsed.totalLength, 3020385);
  assert.equal(parsed.files.length, 12);
  assert.ok(
    parsed.files.some(
      (f) =>
        f.path === '1953_Movie_Trailers_Starting_Monday_00_35_40_28_3mb.mp4' &&
        f.length === 2384112,
    ),
  );
});
