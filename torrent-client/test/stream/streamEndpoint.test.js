import assert from 'node:assert/strict';
import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { createServer } from '../../src/server/httpServer.js';
import { createPieceMap } from '../../src/stream/pieceMap.js';
import { parseRange } from '../../src/stream/streamFile.js';

const PIECE = 10;

// A 30-byte M/movie.mp4 alone in the torrent (pieces [0,10), [10,20), [20,30)), already fully
// on disk where Transmission keeps it; only `states` says which bytes may be served, as with a
// sparse file being filled.
async function withStream(states, fn, { status = 'downloading', error = null } = {}) {
  const dir = await mkdtemp(join(tmpdir(), 'stream-'));
  const content = Buffer.from('0123456789abcdefghijABCDEFGHIJ');
  await mkdir(join(dir, 'M'));
  await writeFile(join(dir, 'M', 'movie.mp4'), content);
  const job = {
    status,
    error,
    outputDir: dir,
    pieceMap: createPieceMap({ pieceLength: PIECE, totalLength: 30, states }),
  };
  const file = {
    index: 0,
    path: 'M/movie.mp4',
    fileName: 'movie.mp4',
    diskPath: 'M/movie.mp4',
    length: 30,
    torrentOffset: 0,
  };
  const manager = {
    getStreamSource: (id, index) =>
      id === 'job' ? { job, file: index === 0 ? file : null } : null,
  };
  const server = createServer({ manager, streamOptions: { stallTimeoutMs: 150 } });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    return await fn(`http://127.0.0.1:${server.address().port}/downloads/job/files/0`, {
      job,
      content,
      setStates: (next) => {
        job.pieceMap = createPieceMap({ pieceLength: PIECE, totalLength: 30, states: next });
      },
    });
  } finally {
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
  }
}

test('parseRange: the three forms, and ranges outside the file', () => {
  assert.deepEqual(parseRange('bytes=5-9', 30), { start: 5, end: 9 });
  assert.deepEqual(parseRange('bytes=25-', 30), { start: 25, end: 29 });
  assert.deepEqual(parseRange('bytes=-4', 30), { start: 26, end: 29 });
  assert.deepEqual(parseRange('bytes=5-100', 30), { start: 5, end: 29 });
  assert.equal(parseRange(undefined, 30), null);
  assert.deepEqual(parseRange('bytes=30-', 30), { unsatisfiable: true });
});

test('a complete file is served whole (200) or by range (206)', async () => {
  await withStream([2, 2, 2], async (url, { content }) => {
    const whole = await fetch(url);
    assert.equal(whole.status, 200);
    assert.equal(whole.headers.get('content-type'), 'video/mp4');
    assert.deepEqual(Buffer.from(await whole.arrayBuffer()), content);

    const part = await fetch(url, { headers: { Range: 'bytes=8-12' } });
    assert.equal(part.status, 206);
    assert.equal(part.headers.get('content-range'), 'bytes 8-12/30');
    assert.equal(await part.text(), '89abc');

    const outside = await fetch(url, { headers: { Range: 'bytes=40-' } });
    assert.equal(outside.status, 416);
  });
});

test('missing bytes are waited for, and only verified bytes are ever sent', async () => {
  await withStream([2, 0, 0], async (url, { setStates }) => {
    const response = fetch(url, { headers: { Range: 'bytes=5-24' } });
    setTimeout(() => setStates([2, 2, 0]), 40);
    setTimeout(() => setStates([2, 2, 2]), 80);
    const res = await response;

    assert.equal(res.status, 206);
    assert.equal(await res.text(), '56789abcdefghijABCDE');
  });
});

test('503 when nothing arrives in time, 410 when the job is over, 404 for an unknown file', async () => {
  await withStream([0, 0, 0], async (url) => {
    const stalled = await fetch(url);
    assert.equal(stalled.status, 503);
    assert.equal(stalled.headers.get('retry-after'), '5');

    assert.equal((await fetch(url.replace(/0$/, '1'))).status, 404);
    assert.equal((await fetch(url.replace('/job/', '/nope/'))).status, 404);
  });

  await withStream(
    [0, 0, 0],
    async (url) => {
      const gone = await fetch(url);
      assert.equal(gone.status, 410);
      assert.deepEqual(await gone.json(), { status: 'failed', error: 'tracker unreachable' });
    },
    { status: 'failed', error: 'tracker unreachable' },
  );
});

test('the piece map gives contiguous bytes, ranges and the bitfield across file boundaries', () => {
  // Pieces of 10 over two files: a.srt [0,15) and b.mp4 [15,40).
  const map = createPieceMap({ pieceLength: 10, totalLength: 40, states: [2, 0, 2, 2] });
  const a = { length: 15, torrentOffset: 0 };
  const b = { length: 25, torrentOffset: 15 };

  assert.equal(map.contiguousBytesFromStart(a), 10);
  assert.equal(map.contiguousBytesFromStart(b), 0);
  assert.equal(map.availableFrom(b, 5), 20);
  assert.deepEqual(map.fileRanges(b), [[5, 25]]);
  assert.equal(map.downloadedBytes(b), 20);
  assert.equal(map.countDone([1, 2, 3]), 2);
  assert.equal(map.bitfieldBase64(), Buffer.from([0b10110000]).toString('base64'));
});
