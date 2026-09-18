import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildHandshake, parseHandshake } from '../../src/peer/handshake.js';

function sampleIds() {
  return {
    infoHash: Buffer.from('0102030405060708090a0b0c0d0e0f1011121314', 'hex'),
    peerId: Buffer.from('-HT0001-abcdefghijkl', 'ascii'),
  };
}

test('builds a 68-byte handshake with the standard pstr', () => {
  const { infoHash, peerId } = sampleIds();
  const handshake = buildHandshake(infoHash, peerId);
  assert.equal(handshake.length, 68);
  assert.equal(handshake[0], 19);
  assert.equal(handshake.toString('ascii', 1, 20), 'BitTorrent protocol');
  assert.ok(handshake.subarray(28, 48).equals(infoHash));
  assert.ok(handshake.subarray(48, 68).equals(peerId));
});

test('defaults the reserved bytes to all zero', () => {
  const { infoHash, peerId } = sampleIds();
  const handshake = buildHandshake(infoHash, peerId);
  assert.ok(handshake.subarray(20, 28).equals(Buffer.alloc(8)));
});

test('round-trips through parseHandshake', () => {
  const { infoHash, peerId } = sampleIds();
  const handshake = buildHandshake(infoHash, peerId);
  const parsed = parseHandshake(handshake);
  assert.equal(parsed.pstr, 'BitTorrent protocol');
  assert.ok(parsed.infoHash.equals(infoHash));
  assert.ok(parsed.peerId.equals(peerId));
  assert.equal(parsed.length, 68);
});

test('parseHandshake reports how many bytes it consumed, ignoring trailing data', () => {
  const { infoHash, peerId } = sampleIds();
  const handshake = buildHandshake(infoHash, peerId);
  const withTrailingMessage = Buffer.concat([handshake, Buffer.from([0, 0, 0, 1, 1])]); // unchoke message tacked on
  const parsed = parseHandshake(withTrailingMessage);
  assert.equal(parsed.length, 68);
});

test('rejects an infoHash that is not 20 bytes', () => {
  const { peerId } = sampleIds();
  assert.throws(() => buildHandshake(Buffer.alloc(19), peerId), RangeError);
});

test('rejects a peerId that is not 20 bytes', () => {
  const { infoHash } = sampleIds();
  assert.throws(() => buildHandshake(infoHash, Buffer.alloc(21)), RangeError);
});

test('parseHandshake rejects a buffer shorter than a full handshake', () => {
  assert.throws(() => parseHandshake(Buffer.alloc(30)), RangeError);
});
