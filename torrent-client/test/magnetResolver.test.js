import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';
import { MagnetError } from '../src/magnet.js';
import { createMagnetResolver } from '../src/magnetResolver.js';
import { PeerError } from '../src/peer/peerError.js';
import { parseTorrentFile } from '../src/torrentFile.js';
import { encode } from './helpers/bencodeEncode.js';

const INFO = encode({
  length: 5,
  name: 'movie.mkv',
  'piece length': 16384,
  pieces: Buffer.alloc(20, 1),
});
const HASH = createHash('sha1').update(INFO).digest('hex');
const MAGNET = `magnet:?xt=urn:btih:${HASH}&tr=udp%3A%2F%2Fdead.test%3A1&tr=udp%3A%2F%2Falive.test%3A2`;

function resolverWith({ peersByTracker = {}, metadataFrom = () => INFO, ...options } = {}) {
  const announced = [];
  const asked = [];
  const resolver = createMagnetResolver({
    announceFn: async ([tracker]) => {
      announced.push(tracker);

      if (!peersByTracker[tracker]) {
        throw new Error('timed out');
      }

      return { peers: peersByTracker[tracker] };
    },
    fetchMetadataFn: async (peer) => {
      asked.push(peer.ip);
      const result = metadataFrom(peer);

      if (result instanceof Error) {
        throw result;
      }

      return result;
    },
    timeoutMs: 300,
    reannounceMs: 20,
    ...options,
  });

  return { resolver, announced, asked };
}

test('every tracker is asked, peers are merged, and a peer without the metadata is skipped', async () => {
  const { resolver, announced, asked } = resolverWith({
    peersByTracker: {
      'udp://alive.test:2': [{ ip: '10.0.0.1', port: 1 }],
      'udp://open.stealth.si:80/announce': [
        { ip: '10.0.0.1', port: 1 },
        { ip: '10.0.0.2', port: 1 },
      ],
    },
    metadataFrom: (peer) => (peer.ip === '10.0.0.1' ? new PeerError('refused') : INFO),
    parallelPeers: 1,
  });

  const torrent = parseTorrentFile(await resolver.resolve(MAGNET));

  assert.equal(torrent.infoHash, HASH);
  assert.ok(
    announced.includes('udp://dead.test:1') &&
      announced.includes('udp://tracker.opentrackr.org:1337/announce'),
  );
  assert.deepEqual(asked, ['10.0.0.1', '10.0.0.2']);
  // The magnet's trackers come first, then the fallback ones.
  assert.equal(torrent.announce, 'udp://dead.test:1');
});

test('inspect then start resolve the metadata once', async () => {
  const { resolver, asked } = resolverWith({
    peersByTracker: { 'udp://alive.test:2': [{ ip: '10.0.0.1', port: 1 }] },
  });

  const [a, b] = await Promise.all([resolver.resolve(MAGNET), resolver.resolve(MAGNET)]);
  await resolver.resolve(MAGNET);

  assert.ok(a.equals(b));
  assert.deepEqual(asked, ['10.0.0.1']);
});

test('without any peer the error says so, and mentions the missing DHT', async () => {
  const { resolver } = resolverWith();

  await assert.rejects(resolver.resolve(MAGNET), (err) => {
    assert.ok(err instanceof MagnetError);
    assert.match(err.message, /No peer found .*no DHT support/);

    return true;
  });
});
