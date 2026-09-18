import { test } from 'node:test';
import assert from 'node:assert/strict';
import { computeFileLayout, computePieceRanges, computeOverlaps } from '../src/torrentLayout.js';

function torrent({ pieceLength, totalLength, pieces, files }) {
  return { pieceLength, totalLength, pieces, files };
}

test('computeFileLayout gives each file its starting offset in the concatenated stream', () => {
  const t = torrent({
    pieceLength: 100,
    totalLength: 30,
    pieces: [],
    files: [
      { path: 'a.txt', length: 10 },
      { path: 'b.txt', length: 15 },
      { path: 'c.txt', length: 5 },
    ],
  });
  assert.deepEqual(computeFileLayout(t), [
    { path: 'a.txt', length: 10, torrentOffset: 0 },
    { path: 'b.txt', length: 15, torrentOffset: 10 },
    { path: 'c.txt', length: 5, torrentOffset: 25 },
  ]);
});

test('computePieceRanges splits the stream into pieceLength chunks, last one shorter', () => {
  const t = torrent({ pieceLength: 10, totalLength: 25, pieces: [0, 1, 2], files: [] });
  assert.deepEqual(computePieceRanges(t), [
    { offset: 0, length: 10 },
    { offset: 10, length: 10 },
    { offset: 20, length: 5 },
  ]);
});

test('computeOverlaps: a range entirely inside one file', () => {
  const layout = [
    { path: 'a.txt', length: 10, torrentOffset: 0 },
    { path: 'b.txt', length: 15, torrentOffset: 10 },
  ];
  const overlaps = computeOverlaps(layout, 12, 5);
  assert.deepEqual(overlaps, [{ file: layout[1], fileOffset: 2, length: 5, rangeOffset: 0 }]);
});

test('computeOverlaps: a range straddling two files', () => {
  const layout = [
    { path: 'a.txt', length: 10, torrentOffset: 0 },
    { path: 'b.txt', length: 15, torrentOffset: 10 },
  ];
  const overlaps = computeOverlaps(layout, 8, 6); // bytes [8,14) -> 2 bytes of a.txt, 4 bytes of b.txt
  assert.deepEqual(overlaps, [
    { file: layout[0], fileOffset: 8, length: 2, rangeOffset: 0 },
    { file: layout[1], fileOffset: 0, length: 4, rangeOffset: 2 },
  ]);
});

test('computeOverlaps: a range spanning three files entirely', () => {
  const layout = [
    { path: 'a.txt', length: 5, torrentOffset: 0 },
    { path: 'b.txt', length: 5, torrentOffset: 5 },
    { path: 'c.txt', length: 5, torrentOffset: 10 },
  ];
  const overlaps = computeOverlaps(layout, 0, 15);
  assert.deepEqual(overlaps, [
    { file: layout[0], fileOffset: 0, length: 5, rangeOffset: 0 },
    { file: layout[1], fileOffset: 0, length: 5, rangeOffset: 5 },
    { file: layout[2], fileOffset: 0, length: 5, rangeOffset: 10 },
  ]);
});

test('computeOverlaps: returns nothing for a range outside every file', () => {
  const layout = [{ path: 'a.txt', length: 10, torrentOffset: 0 }];
  assert.deepEqual(computeOverlaps(layout, 20, 5), []);
});
