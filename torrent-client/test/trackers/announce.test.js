import { test } from 'node:test';
import assert from 'node:assert/strict';
import { announce } from '../../src/trackers/announce.js';
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
