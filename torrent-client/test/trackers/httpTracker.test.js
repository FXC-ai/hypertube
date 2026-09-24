import { test } from 'node:test';
import assert from 'node:assert/strict';
import { encode } from '../helpers/bencodeEncode.js';
import { announceHttpTracker } from '../../src/trackers/httpTracker.js';

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
