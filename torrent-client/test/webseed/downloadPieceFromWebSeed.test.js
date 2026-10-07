import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  downloadPieceFromWebSeed,
  WebSeedError,
} from '../../src/webseed/downloadPieceFromWebSeed.js';

const TORRENT = { name: 'item' };
const LAYOUT = [{ index: 0, path: 'movie.mp4', length: 10, torrentOffset: 0 }];

test('a network failure keeps the real cause code and counts as a connection failure', async () => {
  const fetchImpl = async () => {
    throw new TypeError('fetch failed', {
      cause: Object.assign(new Error('read ECONNRESET'), { code: 'ECONNRESET' }),
    });
  };

  await assert.rejects(
    downloadPieceFromWebSeed('http://seed.test/', TORRENT, LAYOUT, 0, 0, 10, { fetchImpl }),
    (err) =>
      err instanceof WebSeedError &&
      /fetch failed \(ECONNRESET\)/.test(err.message) &&
      err.connectionFailure === true &&
      err.hashMismatch === false,
  );
});

test('an HTTP error status is a piece failure, not a connection failure', async () => {
  const fetchImpl = async () => new Response(null, { status: 404 });

  await assert.rejects(
    downloadPieceFromWebSeed('http://seed.test/', TORRENT, LAYOUT, 0, 0, 10, { fetchImpl }),
    (err) => /status 404/.test(err.message) && err.connectionFailure === false,
  );
});
