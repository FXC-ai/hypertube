// Magnet links (BEP 9): only the info-hash, and optionally a name (dn), trackers (tr),
// web-seeds (ws) and exact sources (xs, the URL of the .torrent itself). The file list and piece hashes (the "info" dictionary) are fetched from peers
// by peer/fetchMetadata.js, then wrapped back into a .torrent so the rest of the client does not
// know where the torrent came from.

export class MagnetError extends Error {
  constructor(message) {
    super(message);
    this.name = 'MagnetError';
  }
}

// Public trackers still answering in 2026, tried after the magnet's own: the trackers listed in
// old magnets (the evaluation sheet's TPB AFK one included) are often dead.
export const FALLBACK_TRACKERS = [
  'udp://tracker.opentrackr.org:1337/announce',
  'udp://open.stealth.si:80/announce',
  'udp://tracker.torrent.eu.org:451/announce',
  'udp://open.demonii.com:1337/announce',
  'udp://exodus.desync.com:6969/announce',
];

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

export function isMagnet(value) {
  return typeof value === 'string' && /^magnet:\?/i.test(value.trim());
}

// { infoHash (40 hex, lower case), name, trackers, webSeeds, exactSources }. Throws MagnetError for a magnet
// without a BitTorrent v1 info-hash (urn:btih).
export function parseMagnet(uri) {
  if (!isMagnet(uri)) {
    throw new MagnetError('Not a magnet link');
  }

  const params = new URLSearchParams(uri.trim().slice(uri.indexOf('?') + 1));
  const topics = [...params.getAll('xt'), ...prefixed(params, 'xt.')];
  const btih = topics.find((topic) => /^urn:btih:/i.test(topic));

  if (!btih) {
    throw new MagnetError('The magnet link has no BitTorrent info-hash (xt=urn:btih:...)');
  }

  return {
    infoHash: decodeInfoHash(btih.slice('urn:btih:'.length)),
    name: params.get('dn') || null,
    trackers: unique([...params.getAll('tr'), ...prefixed(params, 'tr.')]),
    webSeeds: unique(params.getAll('ws')),
    exactSources: unique([...params.getAll('xs'), ...prefixed(params, 'xs.')]),
  };
}

// The .torrent equivalent of a magnet once its info dictionary is known: keys in sorted order,
// `info` copied byte for byte so the info-hash stays the same.
export function buildTorrentFile(infoBytes, { trackers = [], webSeeds = [] } = {}) {
  const parts = [Buffer.from('d')];

  if (trackers.length > 0) {
    parts.push(encodeString('announce'), encodeString(trackers[0]));
    parts.push(
      encodeString('announce-list'),
      Buffer.from('l'),
      ...trackers.map((tracker) =>
        Buffer.concat([Buffer.from('l'), encodeString(tracker), Buffer.from('e')]),
      ),
      Buffer.from('e'),
    );
  }

  parts.push(encodeString('info'), infoBytes);

  if (webSeeds.length > 0) {
    parts.push(
      encodeString('url-list'),
      Buffer.from('l'),
      ...webSeeds.map(encodeString),
      Buffer.from('e'),
    );
  }

  parts.push(Buffer.from('e'));

  return Buffer.concat(parts);
}

function decodeInfoHash(value) {
  if (/^[0-9a-f]{40}$/i.test(value)) {
    return value.toLowerCase();
  }

  if (/^[A-Z2-7]{32}$/i.test(value)) {
    let bits = '';

    for (const char of value.toUpperCase()) {
      bits += BASE32_ALPHABET.indexOf(char).toString(2).padStart(5, '0');
    }

    return Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2))).toString('hex');
  }

  throw new MagnetError(`Invalid info-hash in the magnet link: ${value}`);
}

// "tr.1", "tr.2"... (seen in some magnets) next to the plain repeated "tr".
function prefixed(params, prefix) {
  return [...params.entries()].filter(([key]) => key.startsWith(prefix)).map(([, v]) => v);
}

function unique(values) {
  return [...new Set(values.filter(Boolean))];
}

function encodeString(value) {
  const bytes = Buffer.from(value, 'utf8');

  return Buffer.concat([Buffer.from(`${bytes.length}:`), bytes]);
}
