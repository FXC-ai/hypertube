import { test } from 'node:test';
import assert from 'node:assert/strict';
import { announce, flattenTrackerUrls } from '../../src/trackers/announce.js';
import { TrackerError } from '../../src/trackers/errors.js';

function baseParams() {
  return {
    infoHash: Buffer.alloc(20, 1),
    peerId: Buffer.alloc(20, 2),
    port: 6881,
    left: 100,
    event: 'started',
  };
}

test('flattenTrackerUrls puts announce first, then announce-list tiers, deduplicated', () => {
  const torrent = {
    announce: 'http://a.example/announce',
    announceList: [['http://a.example/announce'], ['udp://b.example:1337'], ['http://c.example/announce']],
  };
  assert.deepEqual(flattenTrackerUrls(torrent), [
    'http://a.example/announce',
    'udp://b.example:1337',
    'http://c.example/announce',
  ]);
});

test('flattenTrackerUrls falls back to just announce when there is no announce-list', () => {
  assert.deepEqual(flattenTrackerUrls({ announce: 'http://a.example/announce' }), ['http://a.example/announce']);
});

test('tries trackers in order and returns the first success', async () => {
  const calls = [];
  const httpAnnouncer = async (url) => {
    calls.push(url);
    throw new TrackerError('first tracker down', { trackerUrl: url });
  };
  const udpAnnouncer = async (url) => {
    calls.push(url);
    return { interval: 60, seeders: 1, leechers: 0, peers: [{ ip: '1.2.3.4', port: 1 }] };
  };

  const result = await announce(
    ['http://dead.example/announce', 'udp://alive.example:1337'],
    baseParams(),
    { httpAnnouncer, udpAnnouncer },
  );

  assert.deepEqual(calls, ['http://dead.example/announce', 'udp://alive.example:1337']);
  assert.equal(result.seeders, 1);
});

test('does not try remaining trackers once one has succeeded', async () => {
  const calls = [];
  const httpAnnouncer = async (url) => {
    calls.push(url);
    return { interval: 60, seeders: 2, leechers: 0, peers: [] };
  };

  await announce(
    ['http://first.example/announce', 'http://second.example/announce'],
    baseParams(),
    { httpAnnouncer },
  );

  assert.deepEqual(calls, ['http://first.example/announce']);
});

test('skips unsupported tracker schemes and still tries the rest', async () => {
  const calls = [];
  const udpAnnouncer = async (url) => {
    calls.push(url);
    return { interval: 60, seeders: 1, leechers: 0, peers: [] };
  };

  const result = await announce(
    ['wss://ws-tracker.example', 'udp://alive.example:1337'],
    baseParams(),
    { udpAnnouncer },
  );

  assert.deepEqual(calls, ['udp://alive.example:1337']);
  assert.equal(result.seeders, 1);
});

test('throws a TrackerError aggregating every failure when all trackers fail', async () => {
  const httpAnnouncer = async (url) => {
    throw new TrackerError('down', { trackerUrl: url });
  };

  await assert.rejects(
    () => announce(['http://a.example/announce', 'http://b.example/announce'], baseParams(), { httpAnnouncer }),
    (err) => {
      assert.ok(err instanceof TrackerError);
      assert.match(err.message, /a\.example/);
      assert.match(err.message, /b\.example/);
      return true;
    },
  );
});

test('deduplicates repeated tracker URLs before trying them', async () => {
  const calls = [];
  const httpAnnouncer = async (url) => {
    calls.push(url);
    return { interval: 60, seeders: 0, leechers: 0, peers: [] };
  };

  await announce(
    ['http://a.example/announce', 'http://a.example/announce'],
    baseParams(),
    { httpAnnouncer },
  );

  assert.deepEqual(calls, ['http://a.example/announce']);
});
