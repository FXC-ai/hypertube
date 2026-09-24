import { createHash } from 'node:crypto';

const SIGNATURE_PREFIX_LENGTH = 64;
const EBML_HEADER = 0x1a45dfa3; // shared by WebM and Matroska

export function computeVideoSignature(buffer) {
  return {
    size: buffer.length,
    sha256: createHash('sha256').update(buffer).digest('hex'),
    firstBytesHex: buffer.subarray(0, SIGNATURE_PREFIX_LENGTH).toString('hex'),
    format: detectContainerFormat(buffer),
  };
}

export function detectContainerFormat(buffer) {
  if (buffer.length >= 8 && buffer.toString('ascii', 4, 8) === 'ftyp') {
    return 'mp4';
  }
  if (buffer.length >= 4 && buffer.readUInt32BE(0) === EBML_HEADER) {
    return 'webm/mkv';
  }
  return 'unknown';
}
