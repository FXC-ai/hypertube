import { randomBytes } from 'node:crypto';
import { createSocket } from 'node:dgram';
import { parseCompactPeers } from './compactPeers.js';
import { TrackerError } from './errors.js';

const PROTOCOL_ID = 0x41727101980n; // BEP15 magic constant
const ACTION_CONNECT = 0;
const ACTION_ANNOUNCE = 1;
const ACTION_ERROR = 3;
const DEFAULT_TIMEOUT_MS = 10000;
const EVENT_CODES = { none: 0, completed: 1, started: 2, stopped: 3 };

export async function announceUdpTracker(announceUrl, params, options = {}) {
  const { timeoutMs = DEFAULT_TIMEOUT_MS } = options;
  const url = new URL(announceUrl);
  const host = url.hostname;
  const port = Number(url.port);

  const socket = createSocket('udp4');

  try {
    const connectionId = await connect(socket, host, port, timeoutMs, announceUrl);

    return await sendAnnounce(socket, host, port, connectionId, params, timeoutMs, announceUrl);
  } finally {
    socket.close();
  }
}

function connect(socket, host, port, timeoutMs, announceUrl) {
  const transactionId = randomTransactionId();
  const request = Buffer.alloc(16);
  request.writeBigUInt64BE(PROTOCOL_ID, 0);
  request.writeUInt32BE(ACTION_CONNECT, 8);
  request.writeUInt32BE(transactionId, 12);

  return sendAndReceive(socket, request, host, port, timeoutMs, announceUrl, (response) => {
    if (response.length < 16) {
      return undefined; // too short to be a real reply; ignore and keep waiting
    }

    const receivedTransactionId = response.readUInt32BE(4);

    if (receivedTransactionId !== transactionId) {
      return undefined;
    }

    const action = response.readUInt32BE(0);

    if (action !== ACTION_CONNECT) {
      throw new TrackerError(`Unexpected action ${action} in connect response`, {
        trackerUrl: announceUrl,
      });
    }

    return response.readBigUInt64BE(8);
  });
}

function sendAnnounce(socket, host, port, connectionId, params, timeoutMs, announceUrl) {
  const transactionId = randomTransactionId();
  const request = Buffer.alloc(98);
  request.writeBigUInt64BE(connectionId, 0);
  request.writeUInt32BE(ACTION_ANNOUNCE, 8);
  request.writeUInt32BE(transactionId, 12);
  params.infoHash.copy(request, 16);
  params.peerId.copy(request, 36);
  request.writeBigUInt64BE(BigInt(params.downloaded ?? 0), 56);
  request.writeBigUInt64BE(BigInt(params.left), 64);
  request.writeBigUInt64BE(BigInt(params.uploaded ?? 0), 72);
  request.writeUInt32BE(EVENT_CODES[params.event ?? 'none'], 80);
  request.writeUInt32BE(0, 84); // ip = 0 (let the tracker use the packet's source address)
  request.writeUInt32BE(randomTransactionId(), 88); // key
  request.writeInt32BE(-1, 92); // num_want = default
  request.writeUInt16BE(params.port, 96);

  return sendAndReceive(socket, request, host, port, timeoutMs, announceUrl, (response) => {
    if (response.length < 8) {
      return undefined;
    }

    const receivedTransactionId = response.readUInt32BE(4);

    if (receivedTransactionId !== transactionId) {
      return undefined;
    }

    const action = response.readUInt32BE(0);

    if (action === ACTION_ERROR) {
      const message = response.subarray(8).toString('utf8');

      throw new TrackerError(`Tracker refused the request: ${message}`, {
        trackerUrl: announceUrl,
      });
    }

    if (action !== ACTION_ANNOUNCE) {
      throw new TrackerError(`Unexpected action ${action} in announce response`, {
        trackerUrl: announceUrl,
      });
    }

    if (response.length < 20) {
      throw new TrackerError('UDP tracker announce response too short', {
        trackerUrl: announceUrl,
      });
    }

    return {
      interval: response.readUInt32BE(8),
      leechers: response.readUInt32BE(12),
      seeders: response.readUInt32BE(16),
      peers: parseCompactPeers(response.subarray(20)),
    };
  });
}

// Sends `request` and resolves with the first onMessage() result that isn't undefined
// (undefined = not our reply, keep listening). Rejects on error or timeout.
function sendAndReceive(socket, request, host, port, timeoutMs, announceUrl, onMessage) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      cleanup();
      reject(new TrackerError('UDP tracker request timed out', { trackerUrl: announceUrl }));
    }, timeoutMs);

    function handleMessage(msg) {
      let result;

      try {
        result = onMessage(msg);
      } catch (err) {
        cleanup();
        reject(err);

        return;
      }

      if (result === undefined) {
        return;
      }

      cleanup();
      resolve(result);
    }

    function handleError(err) {
      cleanup();
      reject(new TrackerError(`UDP socket error: ${err.message}`, { trackerUrl: announceUrl }));
    }

    function cleanup() {
      clearTimeout(timer);
      socket.off('message', handleMessage);
      socket.off('error', handleError);
    }

    socket.on('message', handleMessage);
    socket.on('error', handleError);
    socket.send(request, port, host, (err) => {
      if (err) {
        cleanup();
        reject(
          new TrackerError(`Failed to send UDP request: ${err.message}`, {
            trackerUrl: announceUrl,
          }),
        );
      }
    });
  });
}

function randomTransactionId() {
  return randomBytes(4).readUInt32BE(0);
}
