export const MESSAGE_ID = {
  CHOKE: 0,
  UNCHOKE: 1,
  INTERESTED: 2,
  NOT_INTERESTED: 3,
  HAVE: 4,
  BITFIELD: 5,
  REQUEST: 6,
  PIECE: 7,
  CANCEL: 8,
  PORT: 9,
};

// Sentinel id for a zero-length keep-alive message (it has no id byte on
// the wire at all -- just a 4-byte zero length prefix).
export const KEEP_ALIVE = 'keep-alive';

export function encodeMessage(id, payload = Buffer.alloc(0)) {
  const length = 1 + payload.length;
  const buf = Buffer.alloc(4 + length);
  buf.writeUInt32BE(length, 0);
  buf.writeUInt8(id, 4);
  payload.copy(buf, 5);
  return buf;
}

export function encodeKeepAlive() {
  return Buffer.alloc(4);
}

export function encodeInterested() {
  return encodeMessage(MESSAGE_ID.INTERESTED);
}

export function encodeRequest(index, begin, length) {
  const payload = Buffer.alloc(12);
  payload.writeUInt32BE(index, 0);
  payload.writeUInt32BE(begin, 4);
  payload.writeUInt32BE(length, 8);
  return encodeMessage(MESSAGE_ID.REQUEST, payload);
}

// Pulls every complete length-prefixed message out of `buffer`. TCP gives
// no message boundaries, so a peer's messages can arrive split across
// several 'data' events, or several bundled into one -- `remaining` holds
// whatever trailing partial message wasn't complete yet, to be prepended to
// the next chunk read from the socket.
export function extractMessages(buffer) {
  const messages = [];
  let offset = 0;
  while (offset + 4 <= buffer.length) {
    const length = buffer.readUInt32BE(offset);
    if (length === 0) {
      messages.push({ id: KEEP_ALIVE, payload: Buffer.alloc(0) });
      offset += 4;
      continue;
    }
    if (offset + 4 + length > buffer.length) {
      break;
    }
    const id = buffer.readUInt8(offset + 4);
    const payload = buffer.subarray(offset + 5, offset + 4 + length);
    messages.push({ id, payload });
    offset += 4 + length;
  }
  return { messages, remaining: buffer.subarray(offset) };
}

export function parsePiece(payload) {
  return {
    index: payload.readUInt32BE(0),
    begin: payload.readUInt32BE(4),
    block: payload.subarray(8),
  };
}

export function parseHave(payload) {
  return payload.readUInt32BE(0);
}
