import { decode, BencodeError } from '../bencode.js';
import { parseCompactPeers } from './compactPeers.js';
import { TrackerError } from './errors.js';

const DEFAULT_TIMEOUT_MS = 10000;

export async function announceHttpTracker(announceUrl, params, options = {}) {
  const { timeoutMs = DEFAULT_TIMEOUT_MS, fetchImpl = fetch } = options;
  const url = buildAnnounceUrl(announceUrl, params);

  let response;
  try {
    response = await fetchImpl(url, { signal: AbortSignal.timeout(timeoutMs) });
  } catch (err) {
    throw new TrackerError(`HTTP tracker request failed: ${err.message}`, { trackerUrl: announceUrl });
  }

  if (!response.ok) {
    throw new TrackerError(`HTTP tracker responded with status ${response.status}`, { trackerUrl: announceUrl });
  }

  const body = Buffer.from(await response.arrayBuffer());
  let parsed;
  try {
    parsed = decode(body);
  } catch (err) {
    if (err instanceof BencodeError) {
      throw new TrackerError(`Malformed tracker response: ${err.message}`, { trackerUrl: announceUrl });
    }
    throw err;
  }
  if (!(parsed instanceof Map)) {
    throw new TrackerError('Tracker response is not a dictionary', { trackerUrl: announceUrl });
  }
  if (parsed.has('failure reason')) {
    throw new TrackerError(
      `Tracker refused the request: ${parsed.get('failure reason').toString('utf8')}`,
      { trackerUrl: announceUrl },
    );
  }

  const peersValue = parsed.get('peers');
  const peers = Buffer.isBuffer(peersValue) ? parseCompactPeers(peersValue) : parseNonCompactPeers(peersValue);

  return {
    interval: parsed.get('interval'),
    seeders: parsed.get('complete'),
    leechers: parsed.get('incomplete'),
    peers,
  };
}

function parseNonCompactPeers(list) {
  if (!Array.isArray(list)) {
    return [];
  }
  return list.map((entry) => ({
    ip: entry.get('ip').toString('utf8'),
    port: entry.get('port'),
  }));
}

function buildAnnounceUrl(announceUrl, params) {
  const base = new URL(announceUrl);
  const query = [
    ['info_hash', percentEncodeBytes(params.infoHash)],
    ['peer_id', percentEncodeBytes(params.peerId)],
    ['port', String(params.port)],
    ['uploaded', String(params.uploaded ?? 0)],
    ['downloaded', String(params.downloaded ?? 0)],
    ['left', String(params.left)],
    ['compact', '1'],
  ];
  if (params.event) {
    query.push(['event', params.event]);
  }
  const search = query.map(([key, value]) => `${key}=${value}`).join('&');
  const separator = base.search ? '&' : '?';
  return `${base.origin}${base.pathname}${base.search}${separator}${search}`;
}

// Every byte is escaped, including ones that would technically be safe
// unescaped (letters/digits) -- info_hash and peer_id are raw binary, and
// escaping unconditionally avoids having to special-case which bytes are
// URL-safe. Decodes identically to a "smart" partial-escaping encoder.
function percentEncodeBytes(buffer) {
  let out = '';
  for (const byte of buffer) {
    out += '%' + byte.toString(16).padStart(2, '0');
  }
  return out;
}
