import { test } from 'node:test';
import assert from 'node:assert/strict';
import { generatePeerId } from '../../src/trackers/peerId.js';

test('generates a 20-byte peer id starting with the client prefix', () => {
  const id = generatePeerId();
  assert.equal(id.length, 20);
  assert.equal(id.subarray(0, 8).toString('ascii'), '-HT0001-');
});

test('generates a different suffix on each call', () => {
  const a = generatePeerId();
  const b = generatePeerId();
  assert.notEqual(a.toString('hex'), b.toString('hex'));
});
