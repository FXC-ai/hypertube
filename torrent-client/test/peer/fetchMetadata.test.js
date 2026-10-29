import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createServer } from 'node:net';
import { test } from 'node:test';
import { decodeAt } from '../../src/bencode.js';
import { encodeDict, EXTENDED, fetchMetadataFromPeer } from '../../src/peer/fetchMetadata.js';
import { buildHandshake, parseHandshake } from '../../src/peer/handshake.js';
import { encodeMessage, extractMessages } from '../../src/peer/messages.js';
import { PeerError } from '../../src/peer/peerError.js';
import { encode } from '../helpers/bencodeEncode.js';

const PEER_ID = Buffer.from('-HT0001-abcdefghijkl', 'ascii');
// Bigger than one 16 KiB metadata piece, so it takes several requests.
const INFO = encode({
  length: 1,
  name: 'movie.mkv',
  'piece length': 16384,
  pieces: Buffer.alloc(20 * 1000, 3),
});
const INFO_HASH = createHash('sha1').update(INFO).digest();

// A peer speaking BEP 10 and BEP 9, serving `metadata` (by default the real one).
function startMetadataPeer({ metadata = INFO, extensionBit = true } = {}) {
  const server = createServer((socket) => {
    let buffer = Buffer.alloc(0);
    let handshaken = false;

    socket.on('data', (chunk) => {
      buffer = Buffer.concat([buffer, chunk]);

      if (!handshaken) {
        if (buffer.length < 68) {
          return;
        }

        const { infoHash, length } = parseHandshake(buffer);
        buffer = buffer.subarray(length);
        handshaken = true;
        const reserved = Buffer.alloc(8);
        reserved[5] = extensionBit ? 0x10 : 0;
        socket.write(buildHandshake(infoHash, Buffer.from('-FAKE01-123456789012'), { reserved }));
      }

      const { messages, remaining } = extractMessages(buffer);
      buffer = remaining;

      for (const { id, payload } of messages) {
        if (id !== EXTENDED) {
          continue;
        }

        const [request] = decodeAt(payload, 1);

        if (payload[0] === 0) {
          const handshake = encodeDict({ m: { ut_metadata: 3 }, metadata_size: metadata.length });
          socket.write(encodeMessage(EXTENDED, Buffer.concat([Buffer.from([0]), handshake])));
        } else if (payload[0] === 3) {
          const piece = request.get('piece');
          const data = metadata.subarray(piece * 16384, (piece + 1) * 16384);
          const header = encodeDict({ msg_type: 1, piece, total_size: metadata.length });
          // Our ut_metadata id, announced in the client's extended handshake.
          const ourId = 1;
          socket.write(
            encodeMessage(EXTENDED, Buffer.concat([Buffer.from([ourId]), header, data])),
          );
        }
      }
    });
  });

  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

async function fetchFrom(server, options = {}) {
  try {
    return await fetchMetadataFromPeer(
      { ip: '127.0.0.1', port: server.address().port },
      { infoHash: INFO_HASH, peerId: PEER_ID, timeoutMs: 2000, ...options },
    );
  } finally {
    server.close();
  }
}

test('fetches the info dictionary piece by piece and checks it against the info-hash', async () => {
  const info = await fetchFrom(await startMetadataPeer());

  assert.ok(info.equals(INFO));
});

test('metadata that does not match the info-hash is rejected', async () => {
  const forged = Buffer.from(INFO);
  forged[forged.length - 2] ^= 0xff;

  await assert.rejects(fetchFrom(await startMetadataPeer({ metadata: forged })), (err) => {
    assert.ok(err instanceof PeerError);
    assert.equal(err.hashMismatch, true);

    return true;
  });
});

test('a peer without the extension protocol is rejected at once, not after the timeout', async () => {
  const startedAt = Date.now();

  await assert.rejects(
    fetchFrom(await startMetadataPeer({ extensionBit: false })),
    /does not support the extension protocol/,
  );
  assert.ok(Date.now() - startedAt < 1000);
});
