const INTEGER = 0x69; // 'i'
const LIST = 0x6c; // 'l'
const DICTIONARY = 0x64; // 'd'
const END = 0x65; // 'e'
const COLON = 0x3a; // ':'

export class BencodeError extends Error {
  constructor(message, offset) {
    super(offset === undefined ? message : `${message} (offset ${offset})`);
    this.name = 'BencodeError';
    this.offset = offset;
  }
}

export function decode(buffer) {
  const [value, endPos] = decodeAt(buffer, 0);
  if (endPos !== buffer.length) {
    throw new BencodeError('Trailing data after top-level value', endPos);
  }
  return value;
}

export function decodeAt(buffer, pos) {
  const marker = buffer[pos];
  if (marker === undefined) {
    throw new BencodeError('Unexpected end of input', pos);
  }
  if (marker === INTEGER) return decodeInteger(buffer, pos);
  if (marker === LIST) return decodeList(buffer, pos);
  if (marker === DICTIONARY) return decodeDictionary(buffer, pos);
  if (marker >= 0x30 && marker <= 0x39) return decodeByteString(buffer, pos);
  throw new BencodeError(`Unexpected byte 0x${marker.toString(16)}`, pos);
}

function decodeInteger(buffer, pos) {
  const end = buffer.indexOf(END, pos);
  if (end === -1) {
    throw new BencodeError('Unterminated integer', pos);
  }
  const raw = buffer.toString('ascii', pos + 1, end);
  if (!/^-?\d+$/.test(raw) || raw === '-0' || (raw.length > 1 && raw.startsWith('0')) || raw.startsWith('-0')) {
    throw new BencodeError(`Invalid integer literal "${raw}"`, pos);
  }
  return [Number(raw), end + 1];
}

function decodeByteString(buffer, pos) {
  const colon = buffer.indexOf(COLON, pos);
  if (colon === -1) {
    throw new BencodeError('Unterminated byte string length', pos);
  }
  const lengthRaw = buffer.toString('ascii', pos, colon);
  if (!/^\d+$/.test(lengthRaw)) {
    throw new BencodeError(`Invalid byte string length "${lengthRaw}"`, pos);
  }
  const length = Number(lengthRaw);
  const start = colon + 1;
  const end = start + length;
  if (end > buffer.length) {
    throw new BencodeError('Byte string length exceeds remaining buffer', pos);
  }
  return [buffer.subarray(start, end), end];
}

function decodeList(buffer, pos) {
  let cursor = pos + 1;
  const items = [];
  for (;;) {
    if (cursor >= buffer.length) {
      throw new BencodeError('Unterminated list', pos);
    }
    if (buffer[cursor] === END) {
      return [items, cursor + 1];
    }
    const [value, next] = decodeAt(buffer, cursor);
    items.push(value);
    cursor = next;
  }
}

// Dictionaries decode into a Map, not a plain object: torrent files are
// untrusted external input, and a key like "__proto__" would otherwise
// pollute Object.prototype via naive `obj[key] = value` assignment.
function decodeDictionary(buffer, pos) {
  let cursor = pos + 1;
  const dict = new Map();
  for (;;) {
    if (cursor >= buffer.length) {
      throw new BencodeError('Unterminated dictionary', pos);
    }
    if (buffer[cursor] === END) {
      return [dict, cursor + 1];
    }
    if (!(buffer[cursor] >= 0x30 && buffer[cursor] <= 0x39)) {
      throw new BencodeError('Dictionary key must be a byte string', cursor);
    }
    const [keyBuf, afterKey] = decodeByteString(buffer, cursor);
    const [value, afterValue] = decodeAt(buffer, afterKey);
    dict.set(keyBuf.toString('utf8'), value);
    cursor = afterValue;
  }
}
