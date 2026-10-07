// Thin wrapper over the Transmission RPC (transmission-daemon 4.1). Transmission does the
// BitTorrent part (trackers, peers, web-seeds, piece verification, writing to disk); this
// service decides what to download, where, and serves the verified bytes (see ADR-0009).
//
// No login: the RPC whitelist trusts the Docker network (docker-compose.yml), and the port
// is never published outside localhost.

export class TransmissionError extends Error {
  constructor(message) {
    super(message);
    this.name = 'TransmissionError';
  }
}

export const DEFAULT_TRANSMISSION_URL = 'http://transmission:9091';

// torrent-get "status".
export const STATUS = {
  stopped: 0,
  checkWait: 1,
  checking: 2,
  downloadWait: 3,
  downloading: 4,
  seedWait: 5,
  seeding: 6,
};
// torrent-get "error": 1 and 2 are tracker warnings and errors (web-seeds may still work),
// 3 is a local error such as a disk that cannot be written.
export const LOCAL_ERROR = 3;

const TORRENT_FIELDS = [
  'hashString',
  'status',
  'error',
  'errorString',
  'downloadDir',
  'pieces',
  'peersConnected',
  'webseedsSendingToUs',
];

export function createTransmissionClient({
  baseUrl = process.env.TRANSMISSION_URL ?? DEFAULT_TRANSMISSION_URL,
  fetchImpl = fetch,
} = {}) {
  const url = `${baseUrl.replace(/\/+$/, '')}/transmission/rpc`;
  let sessionId = '';

  // Transmission answers 409 with a session id to send back (its CSRF protection): retried
  // once with the new id.
  async function rpc(method, args = {}) {
    for (let attempt = 0; attempt < 2; attempt += 1) {
      let response;

      try {
        response = await fetchImpl(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Transmission-Session-Id': sessionId },
          body: JSON.stringify({ method, arguments: args }),
        });
      } catch (err) {
        const cause = err.cause?.code ?? err.cause?.message;

        throw new TransmissionError(
          `Transmission unreachable at ${baseUrl}: ${err.message}${cause ? ` (${cause})` : ''}`,
        );
      }

      if (response.status === 409) {
        sessionId = response.headers.get('X-Transmission-Session-Id') ?? '';
        continue;
      }

      if (response.status === 403) {
        throw new TransmissionError(
          'Transmission refused the request (403): its RPC whitelist must include this network (WHITELIST in docker-compose.yml)',
        );
      }

      if (!response.ok) {
        throw new TransmissionError(`Transmission ${method} answered HTTP ${response.status}`);
      }

      const body = await response.json();

      if (body.result !== 'success') {
        throw new TransmissionError(`Transmission ${method} failed: ${body.result}`);
      }

      return body.arguments ?? {};
    }

    throw new TransmissionError(`Transmission ${method}: no valid session id after a 409`);
  }

  return {
    async version() {
      return (await rpc('session-get', { fields: ['version'] })).version;
    },

    // Added paused, so the file selection is set before the first byte is written. Returns
    // the info-hash, also when Transmission already had the torrent (it then ignores
    // downloadDir: the caller checks it).
    async addTorrent({ bytes, downloadDir }) {
      const added = await rpc('torrent-add', {
        metainfo: Buffer.from(bytes).toString('base64'),
        'download-dir': downloadDir,
        paused: true,
      });

      return (added['torrent-added'] ?? added['torrent-duplicate']).hashString;
    },

    async getTorrent(hash) {
      const { torrents } = await rpc('torrent-get', { ids: [hash], fields: TORRENT_FIELDS });

      return torrents?.[0] ?? null;
    },

    // name is relative to downloadDir: "<torrent name>/<path>" for a multi-file torrent.
    async files(hash) {
      const { torrents } = await rpc('torrent-get', { ids: [hash], fields: ['files'] });

      return torrents?.[0]?.files ?? [];
    },

    // Unwanted files are never written, except the bytes of a piece they share with a wanted
    // one. In order (sequential), so a reader moving forward rarely waits.
    async selectFiles(hash, { wanted, unwanted }) {
      await rpc('torrent-set', {
        ids: [hash],
        'files-wanted': wanted,
        'files-unwanted': unwanted,
        sequential_download: true,
      });
    },

    async start(hash) {
      await rpc('torrent-start', { ids: [hash] });
    },

    // Forgets the torrent but keeps its files, so a new POST /downloads rechecks and resumes.
    async remove(hash) {
      await rpc('torrent-remove', { ids: [hash], 'delete-local-data': false });
    },
  };
}

// torrent-get "pieces" is a base64 bitfield (piece 0 = high bit of the first byte). Returns
// one entry per piece: 2 when verified on disk, else 0, the format of the piece map.
export function pieceStatesFromBitfield(base64, pieceCount) {
  const bytes = Buffer.from(base64 ?? '', 'base64');

  return Array.from({ length: pieceCount }, (_, piece) =>
    (bytes[piece >> 3] ?? 0) & (0x80 >> (piece & 7)) ? 2 : 0,
  );
}
