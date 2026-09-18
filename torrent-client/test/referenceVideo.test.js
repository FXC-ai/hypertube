import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { computeVideoSignature } from '../src/videoSignature.js';

// Ground-truth copy of the .mp4 listed inside the archive.org reference
// .torrent (see fixtures.test.js), fetched directly over HTTP (not via
// BitTorrent, which the client doesn't do yet). The comparison JSON next to
// it records this file's signature once, independently of this test run.
// Once #9/#10 can pull the same file through the peer-wire protocol,
// compute its signature too and diff it against this JSON to confirm both
// download paths produce byte-identical files.
const VIDEO_PATH = fileURLToPath(
  new URL('./fixtures/reference-video/1953_movie_trailers_starting_monday.reference.mp4', import.meta.url),
);
const SIGNATURE_JSON_PATH = fileURLToPath(
  new URL('./fixtures/reference-video/1953_movie_trailers_starting_monday.reference.signature.json', import.meta.url),
);

test('reference video matches the committed comparison signature', () => {
  const buffer = readFileSync(VIDEO_PATH);
  const comparisonFile = JSON.parse(readFileSync(SIGNATURE_JSON_PATH, 'utf8'));
  assert.deepEqual(computeVideoSignature(buffer), comparisonFile.signature);
});
