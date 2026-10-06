import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeOverlaps, computeWantedPieces } from '../src/torrentLayout.js';

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

test('computeWantedPieces: only pieces touching a wanted file, boundary pieces included', () => {
  const layout = [
    { index: 0, path: 'meta.sqlite', length: 10, torrentOffset: 0 },
    { index: 1, path: 'movie.mp4', length: 25, torrentOffset: 10 },
    { index: 2, path: 'empty.txt', length: 0, torrentOffset: 35 },
    { index: 3, path: 'extra.mp3', length: 15, torrentOffset: 35 },
  ];
  const pieceRanges = [0, 16, 32].map((offset) => ({ offset, length: Math.min(16, 50 - offset) }));

  // movie.mp4 is bytes [10,35): pieces 0 ([0,16)), 1 ([16,32)) and 2 ([32,50)).
  assert.deepEqual(computeWantedPieces(layout, pieceRanges, new Set([1])), [0, 1, 2]);
  assert.deepEqual(computeWantedPieces(layout, pieceRanges, new Set([0])), [0]);
  assert.deepEqual(computeWantedPieces(layout, pieceRanges, new Set([2])), []);
});
