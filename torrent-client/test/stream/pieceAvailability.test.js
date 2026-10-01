import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createPieceAvailability, PRIORITY } from '../../src/stream/pieceAvailability.js';

// 4 pieces of 10 bytes, 35 bytes in all: a.bin [0,8), movie.mp4 [8,35)
const FILE = { index: 1, path: 'movie.mp4', length: 27, torrentOffset: 8 };

function availability() {
  return createPieceAvailability({ pieceCount: 4, pieceLength: 10, totalLength: 35 });
}

test('file ranges are relative to the file, merged, and stop at the file end', () => {
  const pieces = availability();
  assert.deepEqual(pieces.fileRanges(FILE), []);
  assert.equal(pieces.contiguousBytesFromStart(FILE), 0);

  pieces.mark(0); // file bytes [0,2): the piece also holds a.bin
  pieces.mark(1);
  pieces.mark(3); // short last piece: file bytes [22,27)

  assert.deepEqual(pieces.fileRanges(FILE), [
    [0, 12],
    [22, 27],
  ]);
  assert.equal(pieces.contiguousBytesFromStart(FILE), 12);

  pieces.mark(2);
  assert.deepEqual(pieces.fileRanges(FILE), [[0, 27]]);
});

test('bitfield uses the BitTorrent layout and listeners hear each new piece once', () => {
  const pieces = availability();
  const heard = [];
  pieces.onPiece((i) => heard.push(i));

  pieces.mark(0);
  pieces.mark(3);
  pieces.mark(3);

  assert.equal(Buffer.from(pieces.bitfieldBase64(), 'base64')[0], 0b10010000);
  assert.deepEqual(heard, [0, 3]);
  assert.equal(pieces.count(), 2);
});

test('urgent claims outrank boosted pieces and are counted per request', () => {
  const pieces = availability();
  pieces.setBoosted([0, 3]);
  const releaseA = pieces.claimUrgent([2, 3]);
  const releaseB = pieces.claimUrgent([2]);

  assert.deepEqual([0, 1, 2, 3].map(pieces.rank), [
    PRIORITY.BOOSTED,
    PRIORITY.NORMAL,
    PRIORITY.URGENT,
    PRIORITY.URGENT,
  ]);

  releaseA();
  releaseA(); // releasing twice is harmless
  assert.equal(pieces.rank(2), PRIORITY.URGENT, 'still claimed by the other request');
  assert.equal(pieces.rank(3), PRIORITY.BOOSTED);

  releaseB();
  assert.equal(pieces.rank(2), PRIORITY.NORMAL);
});
