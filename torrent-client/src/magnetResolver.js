import { setTimeout as sleep } from 'node:timers/promises';
import { CancelledError } from './cancelledError.js';
import { buildTorrentFile, FALLBACK_TRACKERS, MagnetError, parseMagnet } from './magnet.js';
import { fetchMetadataFromPeer } from './peer/fetchMetadata.js';
import { announce as defaultAnnounce } from './trackers/announce.js';
import { generatePeerId } from './trackers/peerId.js';

// Turns a magnet link into .torrent bytes: peers come from every tracker of the magnet plus
// FALLBACK_TRACKERS (asked in parallel, peers merged), then up to `parallelPeers` peers at a
// time are asked for the metadata, and the first copy matching the info-hash wins. Trackers are
// asked again every `reannounceMs` while no peer delivered, until `timeoutMs`.
//
// No DHT (BEP 5): a magnet whose swarm is only reachable through the DHT fails with a clear
// error. Results are cached by info-hash, so inspect then start fetch the metadata once.
export function createMagnetResolver({
  announceFn = defaultAnnounce,
  fetchMetadataFn = fetchMetadataFromPeer,
  generatePeerIdFn = generatePeerId,
  timeoutMs = 45000,
  peerTimeoutMs = 10000,
  parallelPeers = 8,
  reannounceMs = 10000,
  trackerPort = 6881,
} = {}) {
  const cache = new Map(); // info-hash -> Promise of .torrent bytes

  function resolve(uri, { signal } = {}) {
    const magnet = parseMagnet(uri);
    let pending = cache.get(magnet.infoHash);

    if (!pending) {
      pending = fetchTorrent(magnet, signal);
      cache.set(magnet.infoHash, pending);
      // A failure is not cached: the next attempt may find peers.
      pending.catch(() => cache.delete(magnet.infoHash));
    }

    return pending;
  }

  async function fetchTorrent(magnet, signal) {
    const infoHash = Buffer.from(magnet.infoHash, 'hex');
    const peerId = generatePeerIdFn();
    const trackers = [...new Set([...magnet.trackers, ...FALLBACK_TRACKERS])];
    const deadline = Date.now() + timeoutMs;
    const tried = new Set();
    const failures = [];
    let peersSeen = 0;

    while (Date.now() < deadline) {
      throwIfAborted(signal);
      const peers = (await peersFrom(trackers, infoHash, peerId)).filter(
        (peer) => !tried.has(`${peer.ip}:${peer.port}`),
      );
      peersSeen += peers.length;
      peers.forEach((peer) => tried.add(`${peer.ip}:${peer.port}`));

      const info = await firstMetadata(peers, { infoHash, peerId, deadline, signal, failures });

      if (info) {
        return buildTorrentFile(info, { trackers, webSeeds: magnet.webSeeds });
      }

      await sleep(Math.max(0, Math.min(reannounceMs, deadline - Date.now())), undefined, {
        signal,
      }).catch(() => {});
    }

    const causes = [...new Set(failures)].slice(0, 5).join(' | ');

    throw new MagnetError(
      peersSeen === 0
        ? `No peer found for magnet ${magnet.infoHash} on ${trackers.length} tracker(s) in ${Math.round(timeoutMs / 1000)} s (no DHT support)`
        : `None of the ${peersSeen} peer(s) found sent the metadata of magnet ${magnet.infoHash} in ${Math.round(timeoutMs / 1000)} s${causes ? `: ${causes}` : ''}`,
    );
  }

  async function peersFrom(trackers, infoHash, peerId) {
    const results = await Promise.allSettled(
      trackers.map((tracker) =>
        announceFn([tracker], { infoHash, peerId, port: trackerPort, left: 1, event: 'started' }),
      ),
    );
    const peers = new Map();

    for (const result of results) {
      for (const peer of result.status === 'fulfilled' ? (result.value.peers ?? []) : []) {
        peers.set(`${peer.ip}:${peer.port}`, peer);
      }
    }

    return [...peers.values()];
  }

  // Asks `parallelPeers` peers at a time; resolves with the first valid metadata or null.
  async function firstMetadata(peers, { infoHash, peerId, deadline, signal, failures }) {
    const controller = new AbortController();
    const combined = signal ? AbortSignal.any([signal, controller.signal]) : controller.signal;
    const queue = [...peers];
    let found = null;

    async function lane() {
      while (!found && queue.length > 0 && Date.now() < deadline && !combined.aborted) {
        const peer = queue.shift();

        try {
          found = await fetchMetadataFn(peer, {
            infoHash,
            peerId,
            timeoutMs: Math.min(peerTimeoutMs, Math.max(1, deadline - Date.now())),
            signal: combined,
          });
          controller.abort();
        } catch (err) {
          if (!(err instanceof CancelledError)) {
            failures.push(err.message);
          }
        }
      }
    }

    await Promise.all(Array.from({ length: Math.min(parallelPeers, peers.length) }, lane));
    throwIfAborted(signal);

    return found;
  }

  return { resolve };
}

function throwIfAborted(signal) {
  if (signal?.aborted) {
    throw new CancelledError();
  }
}
