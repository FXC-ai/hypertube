import { test } from 'node:test';
import assert from 'node:assert/strict';
import { encode } from '../helpers/bencodeEncode.js';
import { announceHttpTracker } from '../../src/trackers/httpTracker.js';
import { TrackerError } from '../../src/trackers/errors.js';

function baseParams(overrides = {}) {
  return {
    infoHash: Buffer.from('0102030405060708090a0b0c0d0e0f1011121314', 'hex'),
    peerId: Buffer.from('-HT0001-abcdefghijkl', 'ascii'),
    port: 6881,
    left: 1000,
    event: 'started',
    ...overrides,
  };
}

function fakeFetch(response) {
  const calls = [];
  const impl = async (url, init) => {
    calls.push({ url, init });
    return response;
  };
  impl.calls = calls;
  return impl;
}

function okResponse(bodyBuffer) {
  return {
    ok: true,
    status: 200,
    arrayBuffer: async () => bodyBuffer.buffer.slice(bodyBuffer.byteOffset, bodyBuffer.byteOffset + bodyBuffer.byteLength),
  };
}

test('percent-encodes raw info_hash and peer_id bytes in the query string', async () => {
  const body = encode({ interval: 60, complete: 0, incomplete: 0, peers: Buffer.alloc(0) });
  const fetchImpl = fakeFetch(okResponse(body));
  await announceHttpTracker('http://tracker.example/announce', baseParams(), { fetchImpl });

  const requestedUrl = fetchImpl.calls[0].url;
  assert.ok(requestedUrl.startsWith('http://tracker.example/announce?'));
  assert.ok(requestedUrl.includes('info_hash=%01%02%03%04%05%06%07%08%09%0a%0b%0c%0d%0e%0f%10%11%12%13%14'));
  assert.ok(requestedUrl.includes('peer_id=%2d%48%54%30%30%30%31%2d%61%62%63%64%65%66%67%68%69%6a%6b%6c'));
  assert.ok(requestedUrl.includes('port=6881'));
  assert.ok(requestedUrl.includes('left=1000'));
  assert.ok(requestedUrl.includes('event=started'));
});

test('parses interval, seeders, leechers and compact peers from a successful response', async () => {
  const peersBuf = Buffer.from([192, 168, 1, 1, 0x1a, 0xe1]);
  const body = encode({ interval: 1800, complete: 5, incomplete: 2, peers: peersBuf });
  const fetchImpl = fakeFetch(okResponse(body));

  const result = await announceHttpTracker('http://tracker.example/announce', baseParams(), { fetchImpl });

  assert.equal(result.interval, 1800);
  assert.equal(result.seeders, 5);
  assert.equal(result.leechers, 2);
  assert.deepEqual(result.peers, [{ ip: '192.168.1.1', port: 6881 }]);
});

test('parses the non-compact peer list form (list of dicts)', async () => {
  const body = encode({
    interval: 900,
    complete: 1,
    incomplete: 0,
    peers: [{ ip: '10.0.0.5', port: 51413, 'peer id': Buffer.alloc(20) }],
  });
  const fetchImpl = fakeFetch(okResponse(body));

  const result = await announceHttpTracker('http://tracker.example/announce', baseParams(), { fetchImpl });
  assert.deepEqual(result.peers, [{ ip: '10.0.0.5', port: 51413 }]);
});

test('throws a TrackerError when the tracker sends a "failure reason"', async () => {
  const body = encode({ 'failure reason': 'invalid info_hash' });
  const fetchImpl = fakeFetch(okResponse(body));

  await assert.rejects(
    () => announceHttpTracker('http://tracker.example/announce', baseParams(), { fetchImpl }),
    (err) => {
      assert.ok(err instanceof TrackerError);
      assert.match(err.message, /invalid info_hash/);
      return true;
    },
  );
});

test('throws a TrackerError on a non-2xx HTTP status', async () => {
  const fetchImpl = fakeFetch({ ok: false, status: 500, arrayBuffer: async () => new ArrayBuffer(0) });

  await assert.rejects(
    () => announceHttpTracker('http://tracker.example/announce', baseParams(), { fetchImpl }),
    TrackerError,
  );
});

test('throws a TrackerError when the response body is not valid bencode', async () => {
  const fetchImpl = fakeFetch(okResponse(Buffer.from('not bencode')));

  await assert.rejects(
    () => announceHttpTracker('http://tracker.example/announce', baseParams(), { fetchImpl }),
    TrackerError,
  );
});

test('throws a TrackerError without hanging when fetch itself rejects (network error / timeout)', async () => {
  const fetchImpl = async () => {
    throw new Error('The operation was aborted');
  };

  await assert.rejects(
    () => announceHttpTracker('http://tracker.example/announce', baseParams(), { fetchImpl }),
    TrackerError,
  );
});
