import { test } from 'node:test';
import assert from 'node:assert/strict';
import { decode, BencodeError } from '../src/bencode.js';

test('decodes a positive integer', () => {
  assert.equal(decode(Buffer.from('i42e')), 42);
});

test('decodes a negative integer', () => {
  assert.equal(decode(Buffer.from('i-42e')), -42);
});

test('decodes zero', () => {
  assert.equal(decode(Buffer.from('i0e')), 0);
});

test('rejects an integer with a leading zero', () => {
  assert.throws(() => decode(Buffer.from('i042e')), BencodeError);
});

test('rejects negative zero', () => {
  assert.throws(() => decode(Buffer.from('i-0e')), BencodeError);
});

test('decodes a byte string', () => {
  const result = decode(Buffer.from('4:spam'));
  assert.ok(Buffer.isBuffer(result));
  assert.equal(result.toString('utf8'), 'spam');
});

test('decodes an empty byte string', () => {
  const result = decode(Buffer.from('0:'));
  assert.equal(result.length, 0);
});

test('preserves raw bytes in a byte string (not necessarily valid UTF-8)', () => {
  const raw = Buffer.from([0x00, 0xff, 0x10, 0x20]);
  const encoded = Buffer.concat([Buffer.from(`${raw.length}:`), raw]);
  const result = decode(encoded);
  assert.ok(raw.equals(result));
});

test('decodes an empty list', () => {
  assert.deepEqual(decode(Buffer.from('le')), []);
});

test('decodes a list of mixed types', () => {
  const result = decode(Buffer.from('li1e4:spami2ee'));
  assert.equal(result.length, 3);
  assert.equal(result[0], 1);
  assert.equal(result[1].toString('utf8'), 'spam');
  assert.equal(result[2], 2);
});

test('decodes an empty dictionary', () => {
  const result = decode(Buffer.from('de'));
  assert.ok(result instanceof Map);
  assert.equal(result.size, 0);
});

test('decodes a dictionary with string and integer values', () => {
  const result = decode(Buffer.from('d3:bar4:spam3:fooi42ee'));
  assert.ok(result instanceof Map);
  assert.equal(result.get('bar').toString('utf8'), 'spam');
  assert.equal(result.get('foo'), 42);
});

test('decodes nested lists and dictionaries', () => {
  const result = decode(Buffer.from('d4:listli1ei2eee'));
  assert.deepEqual(result.get('list'), [1, 2]);
});

test('a dictionary key of "__proto__" does not pollute Object.prototype', () => {
  decode(Buffer.from('d9:__proto__d7:pollutei1eee'));
  assert.equal({}.pollute, undefined);
});

test('rejects an unterminated integer', () => {
  assert.throws(() => decode(Buffer.from('i42')), BencodeError);
});

test('rejects a byte string longer than the remaining buffer', () => {
  assert.throws(() => decode(Buffer.from('10:short')), BencodeError);
});

test('rejects an unterminated list', () => {
  assert.throws(() => decode(Buffer.from('li1e')), BencodeError);
});

test('rejects an unterminated dictionary', () => {
  assert.throws(() => decode(Buffer.from('d3:foo')), BencodeError);
});

test('rejects a dictionary with a non-string key', () => {
  assert.throws(() => decode(Buffer.from('di1ei2ee')), BencodeError);
});

test('rejects trailing data after a valid top-level value', () => {
  assert.throws(() => decode(Buffer.from('i1eextra')), BencodeError);
});

test('rejects an unknown type marker', () => {
  assert.throws(() => decode(Buffer.from('x')), BencodeError);
});

test('rejects empty input', () => {
  assert.throws(() => decode(Buffer.from('')), BencodeError);
});
