const PROTOCOL_ID = 'BitTorrent protocol';

export function buildHandshake(infoHash, peerId, { reserved = Buffer.alloc(8) } = {}) {
  if (infoHash.length !== 20) {
    throw new RangeError(`infoHash must be 20 bytes, got ${infoHash.length}`);
  }

  if (peerId.length !== 20) {
    throw new RangeError(`peerId must be 20 bytes, got ${peerId.length}`);
  }

  return Buffer.concat([
    Buffer.from([PROTOCOL_ID.length]),
    Buffer.from(PROTOCOL_ID, 'ascii'),
    reserved,
    infoHash,
    peerId,
  ]);
}

// Returns `length` (bytes consumed) so callers can slice it off: peers often send their first
// message in the same packet as the handshake.
export function parseHandshake(buffer) {
  if (buffer.length < 1) {
    throw new RangeError('Buffer too short to contain a handshake');
  }

  const pstrlen = buffer[0];
  const total = 1 + pstrlen + 8 + 20 + 20;

  if (buffer.length < total) {
    throw new RangeError(`Buffer too short: handshake needs ${total} bytes, got ${buffer.length}`);
  }

  let offset = 1;
  const pstr = buffer.toString('ascii', offset, offset + pstrlen);
  offset += pstrlen;
  const reserved = buffer.subarray(offset, offset + 8);
  offset += 8;
  const infoHash = buffer.subarray(offset, offset + 20);
  offset += 20;
  const peerId = buffer.subarray(offset, offset + 20);
  offset += 20;

  return { pstr, reserved, infoHash, peerId, length: offset };
}
