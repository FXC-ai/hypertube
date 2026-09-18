import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  MESSAGE_ID,
  KEEP_ALIVE,
  encodeMessage,
  encodeKeepAlive,
  encodeInterested,
  encodeRequest,
  extractMessages,
  parsePiece,
  parseHave,
} from '../../src/peer/messages.js';

test('encodeMessage frames a length prefix + id + payload', () => {
  const buf = encodeMessage(MESSAGE_ID.HAVE, Buffer.from([0, 0, 0, 5]));
  assert.equal(buf.readUInt32BE(0), 5); // 1 (id) + 4 (payload)
  assert.equal(buf.readUInt8(4), MESSAGE_ID.HAVE);
  assert.deepEqual([...buf.subarray(5)], [0, 0, 0, 5]);
});

test('encodeKeepAlive is just a zero length prefix', () => {
  assert.deepEqual([...encodeKeepAlive()], [0, 0, 0, 0]);
});

test('encodeInterested has no payload', () => {
  const buf = encodeInterested();
  assert.equal(buf.length, 5);
  assert.equal(buf.readUInt32BE(0), 1);
  assert.equal(buf.readUInt8(4), MESSAGE_ID.INTERESTED);
});

test('encodeRequest packs index/begin/length as three big-endian uint32s', () => {
  const buf = encodeRequest(3, 16384, 16384);
  const { payload } = extractMessages(buf).messages[0];
  assert.equal(payload.readUInt32BE(0), 3);
  assert.equal(payload.readUInt32BE(4), 16384);
  assert.equal(payload.readUInt32BE(8), 16384);
});

test('extractMessages parses a single complete message', () => {
  const buf = encodeInterested();
  const { messages, remaining } = extractMessages(buf);
  assert.equal(messages.length, 1);
  assert.equal(messages[0].id, MESSAGE_ID.INTERESTED);
  assert.equal(remaining.length, 0);
});

test('extractMessages parses several messages concatenated in one buffer', () => {
  const buf = Buffer.concat([encodeInterested(), encodeMessage(MESSAGE_ID.UNCHOKE), encodeKeepAlive()]);
  const { messages, remaining } = extractMessages(buf);
  assert.equal(messages.length, 3);
  assert.equal(messages[0].id, MESSAGE_ID.INTERESTED);
  assert.equal(messages[1].id, MESSAGE_ID.UNCHOKE);
  assert.equal(messages[2].id, KEEP_ALIVE);
  assert.equal(remaining.length, 0);
});

test('extractMessages leaves a partial trailing message as remaining, unparsed', () => {
  const full = encodeMessage(MESSAGE_ID.PIECE, Buffer.alloc(20));
  const partial = full.subarray(0, full.length - 5); // cut off the last 5 bytes
  const { messages, remaining } = extractMessages(partial);
  assert.equal(messages.length, 0);
  assert.ok(remaining.equals(partial));
});

test('extractMessages resumes correctly once the rest of a split message arrives', () => {
  const full = encodeMessage(MESSAGE_ID.PIECE, Buffer.from('hello world block data!'));
  const firstChunk = full.subarray(0, 6);
  const { messages: none, remaining } = extractMessages(firstChunk);
  assert.equal(none.length, 0);

  const secondChunk = Buffer.concat([remaining, full.subarray(6)]);
  const { messages, remaining: finalRemaining } = extractMessages(secondChunk);
  assert.equal(messages.length, 1);
  assert.equal(messages[0].id, MESSAGE_ID.PIECE);
  assert.equal(finalRemaining.length, 0);
});

test('parsePiece extracts index, begin and the block bytes', () => {
  const payload = Buffer.concat([
    (() => { const b = Buffer.alloc(4); b.writeUInt32BE(2); return b; })(),
    (() => { const b = Buffer.alloc(4); b.writeUInt32BE(16384); return b; })(),
    Buffer.from('block-bytes'),
  ]);
  const { index, begin, block } = parsePiece(payload);
  assert.equal(index, 2);
  assert.equal(begin, 16384);
  assert.equal(block.toString('ascii'), 'block-bytes');
});

test('parseHave extracts the piece index', () => {
  const payload = Buffer.alloc(4);
  payload.writeUInt32BE(42);
  assert.equal(parseHave(payload), 42);
});
