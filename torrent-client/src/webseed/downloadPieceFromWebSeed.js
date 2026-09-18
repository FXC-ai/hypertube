import { createHash } from 'node:crypto';
import { computeOverlaps } from '../torrentLayout.js';
import { CancelledError } from '../cancelledError.js';

export class WebSeedError extends Error {
  constructor(message) {
    super(message);
    this.name = 'WebSeedError';
  }
}

const DEFAULT_TIMEOUT_MS = 20000;

// Downloads one piece via BEP19 web-seeding: for each file the piece
// overlaps (a piece can straddle a file boundary, same as peer-wire writes
// -- see torrentLayout.js), issues a ranged HTTP GET against
// <baseUrl><torrent.name>/<file.path>, the convention archive.org (and
// BEP19 generally) uses for multi-file torrents. The assembled piece is
// verified against pieceHash exactly like a peer-wire piece (#9) -- same
// integrity guarantee no matter where the bytes came from.
export async function downloadPieceFromWebSeed(baseUrl, torrent, fileLayout, pieceIndex, pieceOffset, pieceLength, options = {}) {
  const { pieceHash, timeoutMs = DEFAULT_TIMEOUT_MS, fetchImpl = fetch, signal } = options;

  if (signal?.aborted) {
    throw new CancelledError();
  }

  const requestSignal = signal ? AbortSignal.any([signal, AbortSignal.timeout(timeoutMs)]) : AbortSignal.timeout(timeoutMs);
  const overlaps = computeOverlaps(fileLayout, pieceOffset, pieceLength);
  const chunks = new Array(overlaps.length);

  await Promise.all(
    overlaps.map(async (overlap, i) => {
      const url = buildFileUrl(baseUrl, torrent.name, overlap.file.path);
      const rangeStart = overlap.fileOffset;
      const rangeEnd = overlap.fileOffset + overlap.length - 1;

      let response;
      try {
        response = await fetchImpl(url, {
          headers: { Range: `bytes=${rangeStart}-${rangeEnd}` },
          signal: requestSignal,
        });
      } catch (err) {
        if (signal?.aborted) throw new CancelledError();
        throw new WebSeedError(`Web-seed request failed for ${url}: ${err.message}`);
      }

      if (response.status !== 206 && response.status !== 200) {
        throw new WebSeedError(`Web-seed responded with status ${response.status} for ${url}`);
      }

      const buffer = Buffer.from(await response.arrayBuffer());
      if (buffer.length !== overlap.length) {
        throw new WebSeedError(
          `Web-seed returned ${buffer.length} bytes, expected ${overlap.length}, for ${url} (range ${rangeStart}-${rangeEnd})`,
        );
      }
      chunks[i] = buffer;
    }),
  );

  const pieceBuffer = Buffer.concat(chunks);

  if (pieceHash) {
    const actualHash = createHash('sha1').update(pieceBuffer).digest('hex');
    if (actualHash !== pieceHash) {
      throw new WebSeedError(`Piece ${pieceIndex} hash mismatch via web-seed: expected ${pieceHash}, got ${actualHash}`);
    }
  }

  return pieceBuffer;
}

function buildFileUrl(baseUrl, torrentName, filePath) {
  const normalizedBase = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
  const segments = [torrentName, ...filePath.split('/')].map(encodeURIComponent);
  return normalizedBase + segments.join('/');
}
