import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { encode } from './helpers/bencodeEncode.js';
import { parseTorrentFile, TorrentFileError } from '../src/torrentFile.js';

function minimalSingleFileTorrent(overrides = {}) {
  const info = {
    name: 'sample.mp4',
    'piece length': 16384,
    pieces: Buffer.alloc(20, 1),
    length: 100,
    ...overrides.info,
  };
  const top = {
    announce: 'http://tracker.example/announce',
    info,
    ...overrides.top,
  };
  return encode(top);
}

test('parses announce and announce-list', () => {
  const buffer = encode({
    announce: 'http://tracker.example/announce',
    'announce-list': [['http://tracker.example/announce'], ['http://backup.example/announce']],
    info: {
      name: 'sample.mp4',
      'piece length': 16384,
      pieces: Buffer.alloc(20, 1),
      length: 100,
    },
  });
  const parsed = parseTorrentFile(buffer);
  assert.equal(parsed.announce, 'http://tracker.example/announce');
  assert.deepEqual(parsed.announceList, [
    ['http://tracker.example/announce'],
    ['http://backup.example/announce'],
  ]);
});

test('parses url-list (BEP19) as a list of strings', () => {
  const buffer = encode({
    announce: 'http://tracker.example/announce',
    'url-list': ['https://mirror-a.example/download/', 'https://mirror-b.example/download/'],
    info: { name: 'sample.mp4', 'piece length': 16384, pieces: Buffer.alloc(20, 1), length: 100 },
  });
  const parsed = parseTorrentFile(buffer);
  assert.deepEqual(parsed.urlList, ['https://mirror-a.example/download/', 'https://mirror-b.example/download/']);
});

test('parses url-list given as a single string (BEP19 allows either form)', () => {
  const buffer = encode({
    announce: 'http://tracker.example/announce',
    'url-list': 'https://mirror-a.example/download/',
    info: { name: 'sample.mp4', 'piece length': 16384, pieces: Buffer.alloc(20, 1), length: 100 },
  });
  const parsed = parseTorrentFile(buffer);
  assert.deepEqual(parsed.urlList, ['https://mirror-a.example/download/']);
});

test('urlList is an empty array when the torrent has no url-list', () => {
  const parsed = parseTorrentFile(minimalSingleFileTorrent());
  assert.deepEqual(parsed.urlList, []);
});

test('parses a single-file torrent into one file entry', () => {
  const buffer = minimalSingleFileTorrent();
  const parsed = parseTorrentFile(buffer);
  assert.equal(parsed.name, 'sample.mp4');
  assert.deepEqual(parsed.files, [{ path: 'sample.mp4', length: 100 }]);
  assert.equal(parsed.totalLength, 100);
});

test('parses a multi-file torrent, summing total length', () => {
  const buffer = encode({
    announce: 'http://tracker.example/announce',
    info: {
      name: 'bundle',
      'piece length': 16384,
      pieces: Buffer.alloc(40, 1),
      files: [
        { length: 30, path: ['a.txt'] },
        { length: 70, path: ['sub', 'b.txt'] },
      ],
    },
  });
  const parsed = parseTorrentFile(buffer);
  assert.deepEqual(parsed.files, [
    { path: 'a.txt', length: 30 },
    { path: 'sub/b.txt', length: 70 },
  ]);
  assert.equal(parsed.totalLength, 100);
});

test('splits "pieces" into one hex SHA-1 hash per 20-byte chunk', () => {
  const piece1 = Buffer.alloc(20, 0xaa);
  const piece2 = Buffer.alloc(20, 0xbb);
  const buffer = minimalSingleFileTorrent({ info: { pieces: Buffer.concat([piece1, piece2]) } });
  const parsed = parseTorrentFile(buffer);
  assert.deepEqual(parsed.pieces, [piece1.toString('hex'), piece2.toString('hex')]);
});

test('computes the info-hash as the SHA-1 of the raw bencoded info dict', () => {
  const buffer = minimalSingleFileTorrent();
  const parsed = parseTorrentFile(buffer);
  // independently recompute expected hash from the raw bytes of the encoded info dict
  const infoBytes = encode({
    name: 'sample.mp4',
    'piece length': 16384,
    pieces: Buffer.alloc(20, 1),
    length: 100,
  });
  const expected = createHash('sha1').update(infoBytes).digest('hex');
  assert.equal(parsed.infoHash, expected);
});

test('rejects a non-dictionary top level value', () => {
  assert.throws(() => parseTorrentFile(Buffer.from('i1e')), TorrentFileError);
});

test('rejects a torrent missing the "info" dictionary', () => {
  const buffer = encode({ announce: 'http://tracker.example/announce' });
  assert.throws(() => parseTorrentFile(buffer), TorrentFileError);
});

test('rejects a "pieces" value whose length is not a multiple of 20', () => {
  const buffer = minimalSingleFileTorrent({ info: { pieces: Buffer.alloc(19, 1) } });
  assert.throws(() => parseTorrentFile(buffer), TorrentFileError);
});

test('rejects truncated / corrupt bencode input without crashing', () => {
  const buffer = minimalSingleFileTorrent();
  const truncated = buffer.subarray(0, buffer.length - 10);
  assert.throws(() => parseTorrentFile(truncated), TorrentFileError);
});

test('rejects a single-file torrent missing "length"', () => {
  const buffer = encode({
    announce: 'http://tracker.example/announce',
    info: { name: 'sample.mp4', 'piece length': 16384, pieces: Buffer.alloc(20, 1) },
  });
  assert.throws(() => parseTorrentFile(buffer), TorrentFileError);
});
