import { createHash } from 'node:crypto';
import { connect } from 'node:net';
import { decodeAt } from '../bencode.js';
import { CancelledError } from '../cancelledError.js';
import { buildHandshake, parseHandshake } from './handshake.js';
import { encodeMessage, extractMessages } from './messages.js';
import { PeerError } from './peerError.js';

// Fetches a torrent's "info" dictionary from one peer, for a magnet link:
// - BEP 10 (extension protocol): bit 0x10 of reserved byte 5 in the handshake, then an
//   "extended" message (id 20, sub-id 0) where each side lists the extensions it speaks;
// - BEP 9 (ut_metadata): the metadata is asked for in 16 KiB pieces, then checked against the
//   info-hash, the only thing a magnet can be trusted for.
// Resolves with the raw bencoded info dictionary; rejects with a PeerError otherwise.

export const EXTENDED = 20;
const EXTENDED_HANDSHAKE = 0;
const OUR_UT_METADATA_ID = 1;
const METADATA_PIECE = 16384;
// No real torrent has metadata this big; a peer announcing more is lying or broken.
const MAX_METADATA_SIZE = 16 * 1024 * 1024;

export function fetchMetadataFromPeer(peer, { infoHash, peerId, timeoutMs = 15000, signal }) {
  const label = `${peer.ip}:${peer.port}`;

  return new Promise((resolve, reject) => {
    const socket = connect({ host: peer.ip, port: peer.port });
    let buffer = Buffer.alloc(0);
    let handshakeDone = false;
    let size = null;
    let theirId = null;
    let pieces = [];
    let settled = false;
    const timer = setTimeout(
      () => finish(new PeerError(`No metadata from ${label} within ${timeoutMs} ms`)),
      timeoutMs,
    );
    const onAbort = () => finish(new CancelledError());

    signal?.addEventListener('abort', onAbort);

    function finish(err, value) {
      if (settled) {
        return;
      }

      settled = true;
      clearTimeout(timer);
      signal?.removeEventListener('abort', onAbort);
      socket.destroy();

      if (err) {
        reject(err);
      } else {
        resolve(value);
      }
    }

    function sendExtended(subId, dict, data = Buffer.alloc(0)) {
      socket.write(
        encodeMessage(EXTENDED, Buffer.concat([Buffer.from([subId]), encodeDict(dict), data])),
      );
    }

    socket.on('connect', () => {
      const reserved = Buffer.alloc(8);
      reserved[5] |= 0x10;
      socket.write(buildHandshake(infoHash, peerId, { reserved }));
    });
    socket.on('error', (err) =>
      finish(
        new PeerError(`Socket error with ${label}: ${err.message}`, { connectionFailure: true }),
      ),
    );
    socket.on('close', () => finish(new PeerError(`${label} closed the connection`)));
    socket.on('data', (chunk) => {
      buffer = Buffer.concat([buffer, chunk]);

      try {
        if (!handshakeDone) {
          if (buffer.length < 68) {
            return;
          }

          const handshake = parseHandshake(buffer);
          buffer = buffer.subarray(handshake.length);
          handshakeDone = true;

          if (!handshake.infoHash.equals(infoHash)) {
            throw new PeerError(`${label} answered for another torrent`);
          }

          if ((handshake.reserved[5] & 0x10) === 0) {
            throw new PeerError(`${label} does not support the extension protocol (BEP 10)`);
          }

          sendExtended(EXTENDED_HANDSHAKE, { m: { ut_metadata: OUR_UT_METADATA_ID } });
        }

        const { messages, remaining } = extractMessages(buffer);
        buffer = remaining;

        for (const { id, payload } of messages) {
          if (id === EXTENDED && payload.length > 0) {
            onExtended(payload[0], payload.subarray(1));
          }
        }
      } catch (err) {
        finish(err instanceof PeerError ? err : new PeerError(`${label}: ${err.message}`));
      }
    });

    function onExtended(subId, body) {
      if (subId === EXTENDED_HANDSHAKE) {
        const [dict] = decodeAt(body, 0);
        theirId = dict.get('m')?.get?.('ut_metadata');
        size = dict.get('metadata_size');

        if (!Number.isInteger(theirId) || theirId <= 0) {
          throw new PeerError(`${label} does not serve metadata (ut_metadata)`);
        }

        if (!Number.isInteger(size) || size <= 0 || size > MAX_METADATA_SIZE) {
          throw new PeerError(`${label} announced an invalid metadata_size: ${size}`);
        }

        pieces = new Array(Math.ceil(size / METADATA_PIECE)).fill(null);
        pieces.forEach((_, piece) => sendExtended(theirId, { msg_type: 0, piece }));

        return;
      }

      if (subId !== OUR_UT_METADATA_ID || size === null) {
        return;
      }

      const [dict, dataStart] = decodeAt(body, 0);
      const msgType = dict.get('msg_type');
      const piece = dict.get('piece');

      if (msgType === 2) {
        throw new PeerError(`${label} refused metadata piece ${piece}`);
      }

      if (msgType !== 1 || !Number.isInteger(piece) || piece < 0 || piece >= pieces.length) {
        return;
      }

      pieces[piece] = body.subarray(dataStart);

      if (pieces.every(Boolean)) {
        const info = Buffer.concat(pieces).subarray(0, size);
        const actual = createHash('sha1').update(info).digest();

        if (!actual.equals(infoHash)) {
          throw new PeerError(`Metadata from ${label} does not match the info-hash`, {
            hashMismatch: true,
            actualHash: actual.toString('hex'),
          });
        }

        finish(null, info);
      }
    }
  });
}

// Bencode for the few small dictionaries of the extension messages (keys sorted, as required).
export function encodeDict(dict) {
  const parts = [Buffer.from('d')];

  for (const key of Object.keys(dict).sort()) {
    parts.push(encodeValue(key), encodeValue(dict[key]));
  }

  parts.push(Buffer.from('e'));

  return Buffer.concat(parts);
}

function encodeValue(value) {
  if (Number.isInteger(value)) {
    return Buffer.from(`i${value}e`);
  }

  if (typeof value === 'string') {
    const bytes = Buffer.from(value, 'utf8');

    return Buffer.concat([Buffer.from(`${bytes.length}:`), bytes]);
  }

  return encodeDict(value);
}
