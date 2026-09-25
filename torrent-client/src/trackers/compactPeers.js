// Compact peers (BEP23): 6 bytes each, IPv4 then port, big-endian. Used by both trackers.
export function parseCompactPeers(buffer) {
  if (buffer.length % 6 !== 0) {
    throw new RangeError('Compact peers buffer length must be a multiple of 6');
  }

  const peers = [];

  for (let i = 0; i < buffer.length; i += 6) {
    const ip = `${buffer[i]}.${buffer[i + 1]}.${buffer[i + 2]}.${buffer[i + 3]}`;
    const port = buffer.readUInt16BE(i + 4);
    peers.push({ ip, port });
  }

  return peers;
}
