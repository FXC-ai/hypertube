import assert from 'node:assert/strict';
import { createSocket } from 'node:dgram';
import { test } from 'node:test';
import { announceUdpTracker } from '../../src/trackers/udpTracker.js';

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
function startFakeUdpTracker({ connectionId = 0x0102030405060708n, onAnnounce } = {}) {
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
      if (onAnnounce) {
        onAnnounce(msg);
      }

      const response = Buffer.alloc(20);
      response.writeUInt32BE(1, 0);
      response.writeUInt32BE(transactionId, 4);
      response.writeUInt32BE(1800, 8); // interval
      response.writeUInt32BE(0, 12); // leechers
      response.writeUInt32BE(0, 16); // seeders
      socket.send(response, rinfo.port, rinfo.address);
    }
  });

  return new Promise((resolve) => {
    socket.bind(0, '127.0.0.1', () => resolve(socket));
  });
}

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
