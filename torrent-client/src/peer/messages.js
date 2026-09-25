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

// A keep-alive is a bare 4-byte zero length prefix, with no id byte.
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

// Extracts every complete length-prefixed message. TCP has no message boundaries, so
// `remaining` holds a trailing partial message to prepend to the next chunk.
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
