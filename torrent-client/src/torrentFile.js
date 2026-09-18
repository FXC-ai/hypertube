import { createHash } from 'node:crypto';
import { decodeAt, BencodeError } from './bencode.js';

const DICTIONARY = 0x64; // 'd'
const END = 0x65; // 'e'

export class TorrentFileError extends Error {
  constructor(message) {
    super(message);
    this.name = 'TorrentFileError';
  }
}

export function parseTorrentFile(buffer) {
  if (!Buffer.isBuffer(buffer)) {
    buffer = Buffer.from(buffer);
  }

  let top;
  let infoRaw;
  try {
    [top, infoRaw] = decodeTopLevelCapturingInfo(buffer);
  } catch (err) {
    if (err instanceof BencodeError) {
      throw new TorrentFileError(`Malformed .torrent file: ${err.message}`);
    }
    throw err;
  }

  const announce = requireString(top, 'announce');
  const info = top.get('info');
  if (!(info instanceof Map)) {
    throw new TorrentFileError('Malformed .torrent file: missing or invalid "info" dictionary');
  }

  const name = requireString(info, 'name');
  const pieceLength = requireInteger(info, 'piece length');
  const piecesBuf = requireBytes(info, 'pieces');
  if (piecesBuf.length % 20 !== 0) {
    throw new TorrentFileError('Malformed .torrent file: "pieces" length is not a multiple of 20');
  }
  const pieces = [];
  for (let i = 0; i < piecesBuf.length; i += 20) {
    pieces.push(piecesBuf.subarray(i, i + 20).toString('hex'));
  }

  const { files, totalLength } = info.has('files')
    ? parseMultiFile(info)
    : parseSingleFile(info, name);

  const announceList = top.has('announce-list') ? parseAnnounceList(top.get('announce-list')) : undefined;
  const urlList = top.has('url-list') ? parseUrlList(top.get('url-list')) : [];

  return {
    announce,
    announceList,
    urlList,
    name,
    pieceLength,
    pieces,
    files,
    totalLength,
    infoHash: createHash('sha1').update(infoRaw).digest('hex'),
  };
}

function decodeTopLevelCapturingInfo(buffer) {
  if (buffer[0] !== DICTIONARY) {
    throw new BencodeError('Expected a dictionary at the top level', 0);
  }
  let cursor = 1;
  const dict = new Map();
  let infoRaw = null;
  for (;;) {
    if (cursor >= buffer.length) {
      throw new BencodeError('Unterminated top-level dictionary', cursor);
    }
    if (buffer[cursor] === END) {
      cursor += 1;
      break;
    }
    const [keyBuf, afterKey] = decodeAt(buffer, cursor);
    if (!Buffer.isBuffer(keyBuf)) {
      throw new BencodeError('Dictionary key must be a byte string', cursor);
    }
    const key = keyBuf.toString('utf8');
    const valueStart = afterKey;
    const [value, afterValue] = decodeAt(buffer, afterKey);
    if (key === 'info') {
      infoRaw = buffer.subarray(valueStart, afterValue);
    }
    dict.set(key, value);
    cursor = afterValue;
  }
  if (cursor !== buffer.length) {
    throw new BencodeError('Trailing data after top-level dictionary', cursor);
  }
  if (infoRaw === null) {
    throw new BencodeError('Missing "info" dictionary', 0);
  }
  return [dict, infoRaw];
}

function parseSingleFile(info, name) {
  const length = requireInteger(info, 'length');
  return { files: [{ path: name, length }], totalLength: length };
}

function parseMultiFile(info) {
  const rawFiles = info.get('files');
  if (!Array.isArray(rawFiles) || rawFiles.length === 0) {
    throw new TorrentFileError('Malformed .torrent file: "files" must be a non-empty list');
  }
  let totalLength = 0;
  const files = rawFiles.map((entry, index) => {
    if (!(entry instanceof Map)) {
      throw new TorrentFileError(`Malformed .torrent file: files[${index}] is not a dictionary`);
    }
    const length = requireInteger(entry, 'length');
    const pathParts = entry.get('path');
    if (!Array.isArray(pathParts) || pathParts.length === 0) {
      throw new TorrentFileError(`Malformed .torrent file: files[${index}].path is invalid`);
    }
    const path = pathParts
      .map((part, partIndex) => {
        if (!Buffer.isBuffer(part)) {
          throw new TorrentFileError(`Malformed .torrent file: files[${index}].path[${partIndex}] is not a byte string`);
        }
        return part.toString('utf8');
      })
      .join('/');
    totalLength += length;
    return { path, length };
  });
  return { files, totalLength };
}

function parseAnnounceList(raw) {
  if (!Array.isArray(raw)) {
    throw new TorrentFileError('Malformed .torrent file: "announce-list" is not a list');
  }
  return raw.map((tier, tierIndex) => {
    if (!Array.isArray(tier)) {
      throw new TorrentFileError(`Malformed .torrent file: announce-list[${tierIndex}] is not a list`);
    }
    return tier.map((url, urlIndex) => {
      if (!Buffer.isBuffer(url)) {
        throw new TorrentFileError(`Malformed .torrent file: announce-list[${tierIndex}][${urlIndex}] is not a byte string`);
      }
      return url.toString('utf8');
    });
  });
}

// BEP19: url-list is either a single byte string or a list of them.
function parseUrlList(raw) {
  if (Buffer.isBuffer(raw)) {
    return [raw.toString('utf8')];
  }
  if (!Array.isArray(raw)) {
    throw new TorrentFileError('Malformed .torrent file: "url-list" is not a byte string or a list');
  }
  return raw.map((url, index) => {
    if (!Buffer.isBuffer(url)) {
      throw new TorrentFileError(`Malformed .torrent file: url-list[${index}] is not a byte string`);
    }
    return url.toString('utf8');
  });
}

function requireString(dict, key) {
  const value = dict.get(key);
  if (!Buffer.isBuffer(value)) {
    throw new TorrentFileError(`Malformed .torrent file: missing or invalid "${key}"`);
  }
  return value.toString('utf8');
}

function requireInteger(dict, key) {
  const value = dict.get(key);
  if (typeof value !== 'number' || !Number.isInteger(value)) {
    throw new TorrentFileError(`Malformed .torrent file: missing or invalid "${key}"`);
  }
  return value;
}

function requireBytes(dict, key) {
  const value = dict.get(key);
  if (!Buffer.isBuffer(value)) {
    throw new TorrentFileError(`Malformed .torrent file: missing or invalid "${key}"`);
  }
  return value;
}
