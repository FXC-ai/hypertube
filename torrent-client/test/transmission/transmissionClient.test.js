import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  createTransmissionClient,
  pieceStatesFromBitfield,
  TransmissionError,
} from '../../src/transmission/transmissionClient.js';

test('the 409 session handshake is answered once, then every call carries the session id', async () => {
  const requests = [];
  const fetchImpl = async (url, init) => {
    requests.push({
      url,
      sessionId: init.headers['X-Transmission-Session-Id'],
      body: JSON.parse(init.body),
    });

    if (init.headers['X-Transmission-Session-Id'] !== 'abc') {
      return new Response('', { status: 409, headers: { 'X-Transmission-Session-Id': 'abc' } });
    }

    return Response.json({
      result: 'success',
      arguments: { 'torrent-duplicate': { hashString: 'ff'.repeat(20) } },
    });
  };
  const transmission = createTransmissionClient({
    baseUrl: 'http://transmission:9091/',
    fetchImpl,
  });

  const hash = await transmission.addTorrent({
    bytes: Buffer.from('d4:info'),
    downloadDir: '/movies/7',
  });
  await transmission.selectFiles(hash, { wanted: [1], unwanted: [0, 2] });

  assert.equal(hash, 'ff'.repeat(20));
  assert.equal(requests[0].url, 'http://transmission:9091/transmission/rpc');
  assert.deepEqual(
    requests.map((r) => r.sessionId),
    ['', 'abc', 'abc'],
  );
  assert.deepEqual(requests[1].body, {
    method: 'torrent-add',
    arguments: {
      metainfo: Buffer.from('d4:info').toString('base64'),
      'download-dir': '/movies/7',
      paused: true,
    },
  });
  assert.deepEqual(requests[2].body.arguments, {
    ids: ['ff'.repeat(20)],
    'files-wanted': [1],
    'files-unwanted': [0, 2],
    sequential_download: true,
  });
});

test('a failed RPC result, a 403 and an unreachable daemon are TransmissionErrors that say why', async () => {
  const failing = createTransmissionClient({
    fetchImpl: async () => Response.json({ result: 'invalid or corrupt torrent file' }),
  });
  await assert.rejects(failing.start('x'), /torrent-start failed: invalid or corrupt torrent file/);

  const forbidden = createTransmissionClient({
    fetchImpl: async () => new Response('', { status: 403 }),
  });
  await assert.rejects(forbidden.getTorrent('x'), /WHITELIST/);

  const down = createTransmissionClient({
    baseUrl: 'http://transmission:9091',
    fetchImpl: async () => {
      throw new TypeError('fetch failed', { cause: { code: 'ECONNREFUSED' } });
    },
  });
  await assert.rejects(down.version(), (err) => {
    assert.ok(err instanceof TransmissionError);
    assert.match(
      err.message,
      /unreachable at http:\/\/transmission:9091: fetch failed \(ECONNREFUSED\)/,
    );

    return true;
  });
});

test('pieceStatesFromBitfield: piece 0 is the high bit, missing bytes mean missing pieces', () => {
  assert.deepEqual(pieceStatesFromBitfield('+A==', 6), [2, 2, 2, 2, 2, 0]);
  assert.deepEqual(pieceStatesFromBitfield('', 3), [0, 0, 0]);
});
