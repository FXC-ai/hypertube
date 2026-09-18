import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseCompactPeers } from '../../src/trackers/compactPeers.js';

test('parses a single compact peer', () => {
  const buffer = Buffer.from([192, 168, 1, 1, 0x1a, 0xe1]); // 6881
  assert.deepEqual(parseCompactPeers(buffer), [{ ip: '192.168.1.1', port: 6881 }]);
});

test('parses multiple compact peers back to back', () => {
  const buffer = Buffer.from([
    10, 0, 0, 1, 0x00, 0x50,
    8, 8, 8, 8, 0x01, 0xbb,
  ]);
  assert.deepEqual(parseCompactPeers(buffer), [
    { ip: '10.0.0.1', port: 80 },
    { ip: '8.8.8.8', port: 443 },
  ]);
});

test('parses an empty buffer into an empty peer list', () => {
  assert.deepEqual(parseCompactPeers(Buffer.alloc(0)), []);
});

test('rejects a buffer whose length is not a multiple of 6', () => {
  assert.throws(() => parseCompactPeers(Buffer.alloc(7)), RangeError);
});
