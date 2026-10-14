import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';
import {
  downloadPieceFromWebSeed,
  downloadPiecesFromWebSeed,
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

const sha1 = (buffer) => createHash('sha1').update(buffer).digest('hex');

// Three 4-byte pieces over two files: piece 1 straddles them.
const CONTENT = Buffer.from('aaaabbbbcccc');
const BATCH_TORRENT = {
  name: 'item',
  pieces: [0, 1, 2].map((i) => sha1(CONTENT.subarray(i * 4, i * 4 + 4))),
};
const BATCH_LAYOUT = [
  { index: 0, path: 'M(1931)/movie.mp4', length: 6, torrentOffset: 0 },
  { index: 1, path: 'M(1931)/movie.srt', length: 6, torrentOffset: 6 },
];
const RANGES = [0, 1, 2].map((i) => ({ offset: i * 4, length: 4 }));

// Serves CONTENT split into the two files, in small chunks, and records each request.
function rangedFetch({
  requests = [],
  corrupt = false,
  redirectTo = null,
  stallAfterBytes = null,
} = {}) {
  return async (url, { headers, signal }) => {
    requests.push(url);
    const file = url.endsWith('movie.mp4') ? CONTENT.subarray(0, 6) : CONTENT.subarray(6);
    const [, start, end] = /bytes=(\d+)-(\d+)/.exec(headers.Range).map(Number);
    let body = Buffer.from(file.subarray(start, end + 1));

    if (corrupt && url.endsWith('movie.srt')) {
      body = Buffer.alloc(body.length, 0x7a);
    }

    const stream = new ReadableStream({
      async start(controller) {
        for (let i = 0; i < body.length; i += 2) {
          if (stallAfterBytes !== null && i >= stallAfterBytes) {
            await new Promise((resolve) => signal.addEventListener('abort', resolve));
            controller.error(signal.reason);

            return;
          }

          controller.enqueue(body.subarray(i, i + 2));
        }

        controller.close();
      },
    });
    const response = new Response(stream, { status: 206 });

    if (redirectTo) {
      Object.defineProperty(response, 'url', {
        value: url.replace('http://seed.test/', redirectTo),
      });
    }

    return response;
  };
}

test('one request per file serves a whole run of pieces, each delivered as soon as it is complete', async () => {
  const requests = [];
  const delivered = [];
  await downloadPiecesFromWebSeed(
    'http://seed.test/',
    BATCH_TORRENT,
    BATCH_LAYOUT,
    RANGES,
    [0, 1, 2],
    {
      fetchImpl: rangedFetch({ requests }),
      onPiece: (pieceIndex, buffer) => delivered.push([pieceIndex, buffer.toString()]),
    },
  );

  assert.deepEqual(delivered, [
    [0, 'aaaa'],
    [1, 'bbbb'],
    [2, 'cccc'],
  ]);
  assert.deepEqual(
    requests,
    ['http://seed.test/item/M(1931)/movie.mp4', 'http://seed.test/item/M(1931)/movie.srt'].map(
      (url) => url.replace('M(1931)', encodeURIComponent('M(1931)')),
    ),
  );
});

test('a corrupted piece in the run is reported on its own, the others are still delivered', async () => {
  const delivered = [];
  const bad = [];
  await downloadPiecesFromWebSeed(
    'http://seed.test/',
    BATCH_TORRENT,
    BATCH_LAYOUT,
    RANGES,
    [0, 1, 2],
    {
      fetchImpl: rangedFetch({ corrupt: true }),
      onPiece: (pieceIndex) => delivered.push(pieceIndex),
      onBadPiece: (pieceIndex, err) => bad.push([pieceIndex, err.hashMismatch]),
    },
  );

  assert.deepEqual(delivered, [0]);
  assert.deepEqual(bad, [
    [1, true],
    [2, true],
  ]);
});

test('the server a redirect led to is remembered for the next requests', async () => {
  const requests = [];
  const urlCache = new Map();
  const options = {
    fetchImpl: rangedFetch({ requests, redirectTo: 'http://node7.test/items/' }),
    onPiece: () => {},
    urlCache,
  };
  await downloadPiecesFromWebSeed(
    'http://seed.test/',
    BATCH_TORRENT,
    BATCH_LAYOUT,
    RANGES,
    [0],
    options,
  );
  await downloadPiecesFromWebSeed(
    'http://seed.test/',
    BATCH_TORRENT,
    BATCH_LAYOUT,
    RANGES,
    [0],
    options,
  );

  assert.ok(requests[0].startsWith('http://seed.test/'));
  assert.ok(requests[1].startsWith('http://node7.test/items/'), requests[1]);
});

test('a response that stops sending fails after idleTimeoutMs, as slow rather than unreachable', async () => {
  await assert.rejects(
    downloadPiecesFromWebSeed('http://seed.test/', BATCH_TORRENT, BATCH_LAYOUT, RANGES, [0, 1, 2], {
      fetchImpl: rangedFetch({ stallAfterBytes: 2 }),
      onPiece: () => {},
      idleTimeoutMs: 100,
    }),
    (err) =>
      err instanceof WebSeedError && /stalled/.test(err.message) && err.connectionFailure === false,
  );
});
