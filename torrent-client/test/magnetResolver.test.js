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

function resolverWith({
  peersByTracker = {},
  metadataFrom = () => INFO,
  exactSource = null,
  ...options
} = {}) {
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
    fetchImpl: async (url, { signal } = {}) => {
      if (!exactSource) {
        throw new Error('no exact source in this test');
      }

      return exactSource(url, signal);
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

test('an exact source (xs) with the right info-hash is used without asking any peer', async () => {
  const { buildTorrentFile } = await import('../src/magnet.js');
  const torrent = buildTorrentFile(INFO, { trackers: ['udp://x.test:1'] });
  const fetched = [];
  const { resolver, announced, asked } = resolverWith({
    exactSource: async (url) => {
      fetched.push(url);

      return new Response(torrent);
    },
  });

  const bytes = await resolver.resolve(
    `${MAGNET}&xs=${encodeURIComponent('https://seed.test/a.torrent')}`,
  );

  assert.ok(bytes.equals(torrent));
  assert.deepEqual(fetched, ['https://seed.test/a.torrent']);
  assert.deepEqual(announced, []);
  assert.deepEqual(asked, []);
});

test('an exact source with another info-hash is ignored and the peers are asked instead', async () => {
  const { buildTorrentFile } = await import('../src/magnet.js');
  const other = buildTorrentFile(
    encode({ length: 9, name: 'other.mkv', 'piece length': 16384, pieces: Buffer.alloc(20, 9) }),
  );
  const { resolver, asked } = resolverWith({
    peersByTracker: { 'udp://alive.test:2': [{ ip: '10.0.0.1', port: 1 }] },
    exactSource: async () => new Response(other),
  });

  const torrent = parseTorrentFile(
    await resolver.resolve(`${MAGNET}&xs=${encodeURIComponent('https://seed.test/wrong.torrent')}`),
  );

  assert.equal(torrent.infoHash, HASH);
  assert.deepEqual(asked, ['10.0.0.1']);
});

test('cancelling one caller does not fail the others; the fetch stops once nobody waits', async () => {
  const { CancelledError } = await import('../src/cancelledError.js');
  let release;
  let fetchSignal;
  const gate = new Promise((resolve) => {
    release = resolve;
  });
  const resolver = createMagnetResolver({
    announceFn: async () => ({ peers: [{ ip: '10.0.0.1', port: 1 }] }),
    fetchMetadataFn: async (peer, { signal }) => {
      fetchSignal = signal;
      await gate;

      return INFO;
    },
    timeoutMs: 1000,
  });
  const first = new AbortController();
  const second = new AbortController();

  const cancelled = resolver.resolve(MAGNET, { signal: first.signal });
  const kept = resolver.resolve(MAGNET, { signal: second.signal });
  await new Promise((resolve) => setTimeout(resolve, 20));
  first.abort();

  await assert.rejects(cancelled, CancelledError);
  assert.equal(fetchSignal.aborted, false, 'the other caller still waits');
  release();
  assert.equal(parseTorrentFile(await kept).infoHash, HASH);

  // Nobody left waiting: the shared fetch is aborted, not left running.
  let releaseLonely;
  let lonelySignal;
  const lonelyGate = new Promise((resolve) => {
    releaseLonely = resolve;
  });
  const lonely = createMagnetResolver({
    announceFn: async () => ({ peers: [{ ip: '10.0.0.1', port: 1 }] }),
    fetchMetadataFn: async (peer, { signal }) => {
      lonelySignal = signal;
      await lonelyGate;

      return INFO;
    },
    timeoutMs: 1000,
  });
  const only = new AbortController();
  const waiting = lonely.resolve(MAGNET, { signal: only.signal });
  await new Promise((resolve) => setTimeout(resolve, 20));
  only.abort();

  await assert.rejects(waiting, CancelledError);
  assert.equal(lonelySignal.aborted, true);
  releaseLonely();
});

test('slow exact sources share the overall timeout: the answer never comes later than timeoutMs', async () => {
  const fetched = [];
  const resolver = createMagnetResolver({
    announceFn: async () => ({ peers: [] }),
    fetchMetadataFn: async () => INFO,
    fetchImpl: async (url, { signal }) => {
      fetched.push(url);

      // Never answers: only the timeout signal ends it. AbortSignal.timeout() does not keep
      // Node alive, so a timer stands in for the open socket a real request would hold.
      return new Promise((resolve, reject) => {
        const socket = setTimeout(() => {}, 10000);

        signal.addEventListener('abort', () => {
          clearTimeout(socket);
          reject(signal.reason);
        });
      });
    },
    timeoutMs: 300,
    exactSourceTimeoutMs: 200,
    reannounceMs: 20,
  });
  const sources = ['a', 'b', 'c', 'd', 'e']
    .map((name) => `&xs=${encodeURIComponent(`https://slow.test/${name}.torrent`)}`)
    .join('');
  const startedAt = Date.now();

  await assert.rejects(resolver.resolve(`${MAGNET}${sources}`), MagnetError);

  // 300 ms in all, not 5 x 200 ms of exact sources followed by 300 ms of peers.
  assert.ok(Date.now() - startedAt < 600, `took ${Date.now() - startedAt} ms`);
  assert.ok(fetched.length <= 3, `${fetched.length} exact sources tried`);
});
