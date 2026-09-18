import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { computeVideoSignature, detectContainerFormat } from '../src/videoSignature.js';

test('detects mp4 via the "ftyp" box at offset 4', () => {
  const buffer = Buffer.concat([
    Buffer.from([0x00, 0x00, 0x00, 0x18]),
    Buffer.from('ftypmp42', 'ascii'),
    Buffer.alloc(16),
  ]);
  assert.equal(detectContainerFormat(buffer), 'mp4');
});

test('detects webm/mkv via the EBML header', () => {
  const buffer = Buffer.from([0x1a, 0x45, 0xdf, 0xa3, 0x00, 0x00]);
  assert.equal(detectContainerFormat(buffer), 'webm/mkv');
});

test('reports unknown for unrecognized bytes', () => {
  const buffer = Buffer.from('not a video file at all', 'ascii');
  assert.equal(detectContainerFormat(buffer), 'unknown');
});

test('reports unknown rather than throwing on a buffer shorter than any signature', () => {
  assert.equal(detectContainerFormat(Buffer.from([0x00, 0x01])), 'unknown');
});

test('computeVideoSignature reports size, sha256, first bytes and format', () => {
  const buffer = Buffer.concat([
    Buffer.from([0x00, 0x00, 0x00, 0x18]),
    Buffer.from('ftypmp42', 'ascii'),
    Buffer.alloc(100, 0xab),
  ]);
  const signature = computeVideoSignature(buffer);
  assert.equal(signature.size, buffer.length);
  assert.equal(signature.sha256, createHash('sha256').update(buffer).digest('hex'));
  assert.equal(signature.firstBytesHex, buffer.subarray(0, 64).toString('hex'));
  assert.equal(signature.format, 'mp4');
});

test('computeVideoSignature does not fail on a buffer shorter than the signature prefix', () => {
  const buffer = Buffer.from('short', 'ascii');
  const signature = computeVideoSignature(buffer);
  assert.equal(signature.size, 5);
  assert.equal(signature.firstBytesHex, buffer.toString('hex'));
});
