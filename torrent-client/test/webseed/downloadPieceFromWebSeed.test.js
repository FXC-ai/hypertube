import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { computeFileLayout } from '../../src/torrentLayout.js';
import { downloadPieceFromWebSeed, WebSeedError } from '../../src/webseed/downloadPieceFromWebSeed.js';

function sha1Hex(buffer) {
  return createHash('sha1').update(buffer).digest('hex');
}

function singleFileTorrent(content) {
  return {
    name: 'item123',
    totalLength: content.length,
    files: [{ path: 'movie.mp4', length: content.length }],
  };
}

function multiFileTorrent(fileA, fileB) {
  return {
    name: 'item123',
    totalLength: fileA.length + fileB.length,
    files: [
      { path: 'a.txt', length: fileA.length },
      { path: 'b.mp4', length: fileB.length },
    ],
  };
}

function fakeFetch(handler) {
  const calls = [];
  const impl = async (url, init) => {
    calls.push({ url, headers: init?.headers });
    return handler(url, init);
  };
  impl.calls = calls;
  return impl;
}

function rangeResponse(fullBuffer, rangeHeader) {
  const match = /bytes=(\d+)-(\d+)/.exec(rangeHeader);
  const start = Number(match[1]);
  const end = Number(match[2]);
  const slice = fullBuffer.subarray(start, end + 1);
  return {
    status: 206,
    ok: true,
    arrayBuffer: async () => slice.buffer.slice(slice.byteOffset, slice.byteOffset + slice.byteLength),
  };
}

test('downloads a piece entirely inside one file via a ranged GET', async () => {
  const content = Buffer.from('0123456789abcdefghij');
  const torrent = singleFileTorrent(content);
  const fileLayout = computeFileLayout(torrent);
  const fetchImpl = fakeFetch((url, init) => rangeResponse(content, init.headers.Range));

  const piece = await downloadPieceFromWebSeed('https://example.com/download/', torrent, fileLayout, 0, 5, 10, {
    pieceHash: sha1Hex(content.subarray(5, 15)),
    fetchImpl,
  });

  assert.ok(piece.equals(content.subarray(5, 15)));
  assert.equal(fetchImpl.calls.length, 1);
  assert.equal(fetchImpl.calls[0].url, 'https://example.com/download/item123/movie.mp4');
  assert.equal(fetchImpl.calls[0].headers.Range, 'bytes=5-14');
});

test('splits a piece straddling two files into two ranged GETs and reassembles in order', async () => {
  const fileA = Buffer.from('AAAAAAAAAA'); // 10 bytes
  const fileB = Buffer.from('BBBBBBBBBB'); // 10 bytes
  const torrent = multiFileTorrent(fileA, fileB);
  const fileLayout = computeFileLayout(torrent);
  const fetchImpl = fakeFetch((url, init) => {
    const content = url.endsWith('a.txt') ? fileA : fileB;
    return rangeResponse(content, init.headers.Range);
  });

  // piece spans [8, 14) -> last 2 bytes of a.txt + first 4 bytes of b.txt
  const piece = await downloadPieceFromWebSeed('https://example.com/download/', torrent, fileLayout, 0, 8, 6, {
    fetchImpl,
  });

  assert.equal(piece.toString('ascii'), 'AABBBB');
  assert.equal(fetchImpl.calls.length, 2);
});

test('throws WebSeedError when the assembled piece fails hash verification', async () => {
  const content = Buffer.from('0123456789');
  const torrent = singleFileTorrent(content);
  const fileLayout = computeFileLayout(torrent);
  const fetchImpl = fakeFetch((url, init) => rangeResponse(content, init.headers.Range));

  await assert.rejects(
    () => downloadPieceFromWebSeed('https://example.com/download/', torrent, fileLayout, 0, 0, 10, {
      pieceHash: 'not-the-real-hash',
      fetchImpl,
    }),
    WebSeedError,
  );
});

test('throws WebSeedError on a non-206/200 response', async () => {
  const torrent = singleFileTorrent(Buffer.from('0123456789'));
  const fileLayout = computeFileLayout(torrent);
  const fetchImpl = fakeFetch(() => ({ status: 404, ok: false, arrayBuffer: async () => new ArrayBuffer(0) }));

  await assert.rejects(
    () => downloadPieceFromWebSeed('https://example.com/download/', torrent, fileLayout, 0, 0, 10, { fetchImpl }),
    WebSeedError,
  );
});

test('throws WebSeedError when the server returns fewer bytes than requested', async () => {
  const torrent = singleFileTorrent(Buffer.from('0123456789'));
  const fileLayout = computeFileLayout(torrent);
  const fetchImpl = fakeFetch(() => ({
    status: 206,
    ok: true,
    arrayBuffer: async () => Buffer.from('short').buffer,
  }));

  await assert.rejects(
    () => downloadPieceFromWebSeed('https://example.com/download/', torrent, fileLayout, 0, 0, 10, { fetchImpl }),
    WebSeedError,
  );
});

test('URL-encodes file path segments', async () => {
  const torrent = {
    name: 'item with spaces',
    totalLength: 5,
    files: [{ path: 'sub dir/movie.mp4', length: 5 }],
  };
  const fileLayout = computeFileLayout(torrent);
  const content = Buffer.from('hello');
  const fetchImpl = fakeFetch((url, init) => rangeResponse(content, init.headers.Range));

  await downloadPieceFromWebSeed('https://example.com/download/', torrent, fileLayout, 0, 0, 5, { fetchImpl });

  assert.equal(fetchImpl.calls[0].url, 'https://example.com/download/item%20with%20spaces/sub%20dir/movie.mp4');
});

test('rejects immediately if the signal is already aborted', async () => {
  const torrent = singleFileTorrent(Buffer.from('0123456789'));
  const fileLayout = computeFileLayout(torrent);
  const controller = new AbortController();
  controller.abort();

  await assert.rejects(
    () => downloadPieceFromWebSeed('https://example.com/download/', torrent, fileLayout, 0, 0, 10, {
      fetchImpl: fakeFetch(() => rangeResponse(Buffer.alloc(10), 'bytes=0-9')),
      signal: controller.signal,
    }),
  );
});
