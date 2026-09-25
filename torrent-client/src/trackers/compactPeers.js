// Compact peer format (BEP23): 6 bytes per peer, 4-byte big-endian IPv4
// followed by a 2-byte big-endian port. Shared by HTTP and UDP tracker
// responses, which both use it.
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
