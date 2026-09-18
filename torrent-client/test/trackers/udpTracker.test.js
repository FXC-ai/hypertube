import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createSocket } from 'node:dgram';
import { announceUdpTracker } from '../../src/trackers/udpTracker.js';
import { TrackerError } from '../../src/trackers/errors.js';

const PROTOCOL_ID = 0x41727101980n;

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

// Minimal local BEP15 tracker, just enough to exercise the client's wire
// format without depending on a real tracker being reachable in CI.
function startFakeUdpTracker({ connectionId = 0x0102030405060708n, seeders = 3, leechers = 1, peers = Buffer.alloc(0), onAnnounce } = {}) {
  const socket = createSocket('udp4');
  socket.on('message', (msg, rinfo) => {
    const action = msg.readUInt32BE(8);
    const transactionId = msg.readUInt32BE(12);
    if (action === 0) {
      const response = Buffer.alloc(16);
      response.writeUInt32BE(0, 0);
      response.writeUInt32BE(transactionId, 4);
      response.writeBigUInt64BE(connectionId, 8);
      socket.send(response, rinfo.port, rinfo.address);
    } else if (action === 1) {
      if (onAnnounce) onAnnounce(msg);
      const response = Buffer.alloc(20 + peers.length);
      response.writeUInt32BE(1, 0);
      response.writeUInt32BE(transactionId, 4);
      response.writeUInt32BE(1800, 8); // interval
      response.writeUInt32BE(leechers, 12);
      response.writeUInt32BE(seeders, 16);
      peers.copy(response, 20);
      socket.send(response, rinfo.port, rinfo.address);
    }
  });
  return new Promise((resolve) => {
    socket.bind(0, '127.0.0.1', () => resolve(socket));
  });
}

test('completes connect + announce handshake and parses the response', async () => {
  const peers = Buffer.from([192, 168, 1, 1, 0x1a, 0xe1, 10, 0, 0, 2, 0x00, 0x50]);
  const socket = await startFakeUdpTracker({ seeders: 7, leechers: 2, peers });
  const { port } = socket.address();
  try {
    const result = await announceUdpTracker(`udp://127.0.0.1:${port}`, baseParams(), { timeoutMs: 3000 });
    assert.equal(result.interval, 1800);
    assert.equal(result.seeders, 7);
    assert.equal(result.leechers, 2);
    assert.deepEqual(result.peers, [
      { ip: '192.168.1.1', port: 6881 },
      { ip: '10.0.0.2', port: 80 },
    ]);
  } finally {
    socket.close();
  }
});

test('sends a well-formed BEP15 connect request', async () => {
  let seenConnect = null;
  const socket = createSocket('udp4');
  socket.on('message', (msg, rinfo) => {
    if (seenConnect === null) {
      seenConnect = msg;
    }
    const response = Buffer.alloc(16);
    response.writeUInt32BE(0, 0);
    response.writeUInt32BE(msg.readUInt32BE(12), 4);
    response.writeBigUInt64BE(0x1122334455667788n, 8);
    socket.send(response, rinfo.port, rinfo.address);
    // never answer the announce -- we only care about the connect packet here
  });
  await new Promise((resolve) => socket.bind(0, '127.0.0.1', resolve));
  const { port } = socket.address();

  await assert.rejects(() => announceUdpTracker(`udp://127.0.0.1:${port}`, baseParams(), { timeoutMs: 500 }));

  assert.equal(seenConnect.length, 16);
  assert.equal(seenConnect.readBigUInt64BE(0), PROTOCOL_ID);
  assert.equal(seenConnect.readUInt32BE(8), 0); // action = connect
  socket.close();
});

test('sends a well-formed BEP15 announce request using the connection id', async () => {
  let seenAnnounce = null;
  const socket = await startFakeUdpTracker({
    onAnnounce: (msg) => {
      seenAnnounce = msg;
    },
  });
  const { port } = socket.address();
  const params = baseParams({ left: 424242, port: 6882 });
  try {
    await announceUdpTracker(`udp://127.0.0.1:${port}`, params, { timeoutMs: 3000 });
  } finally {
    socket.close();
  }

  assert.equal(seenAnnounce.length, 98);
  assert.equal(seenAnnounce.readUInt32BE(8), 1); // action = announce
  assert.ok(params.infoHash.equals(seenAnnounce.subarray(16, 36)));
  assert.ok(params.peerId.equals(seenAnnounce.subarray(36, 56)));
  assert.equal(seenAnnounce.readBigUInt64BE(64), 424242n); // left
  assert.equal(seenAnnounce.readUInt32BE(80), 2); // event = started
  assert.equal(seenAnnounce.readUInt16BE(96), 6882); // port
});

test('throws a TrackerError on the BEP15 error action', async () => {
  const socket = createSocket('udp4');
  socket.on('message', (msg, rinfo) => {
    const action = msg.readUInt32BE(8);
    const transactionId = msg.readUInt32BE(12);
    if (action === 0) {
      const response = Buffer.alloc(16);
      response.writeUInt32BE(0, 0);
      response.writeUInt32BE(transactionId, 4);
      response.writeBigUInt64BE(1n, 8);
      socket.send(response, rinfo.port, rinfo.address);
    } else if (action === 1) {
      const message = Buffer.from('bad request', 'utf8');
      const response = Buffer.alloc(8 + message.length);
      response.writeUInt32BE(3, 0); // action = error
      response.writeUInt32BE(transactionId, 4);
      message.copy(response, 8);
      socket.send(response, rinfo.port, rinfo.address);
    }
  });
  await new Promise((resolve) => socket.bind(0, '127.0.0.1', resolve));
  const { port } = socket.address();

  try {
    await assert.rejects(
      () => announceUdpTracker(`udp://127.0.0.1:${port}`, baseParams(), { timeoutMs: 3000 }),
      (err) => {
        assert.ok(err instanceof TrackerError);
        assert.match(err.message, /bad request/);
        return true;
      },
    );
  } finally {
    socket.close();
  }
});

test('rejects without hanging when the tracker never responds', async () => {
  const socket = createSocket('udp4');
  socket.on('message', () => {}); // black hole: never reply
  await new Promise((resolve) => socket.bind(0, '127.0.0.1', resolve));
  const { port } = socket.address();

  const start = Date.now();
  try {
    await assert.rejects(
      () => announceUdpTracker(`udp://127.0.0.1:${port}`, baseParams(), { timeoutMs: 300 }),
      TrackerError,
    );
    assert.ok(Date.now() - start < 2000, 'should reject close to the timeout, not hang');
  } finally {
    socket.close();
  }
});
