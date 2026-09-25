import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeOverlaps } from '../src/torrentLayout.js';

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
