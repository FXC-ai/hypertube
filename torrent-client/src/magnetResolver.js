import { setTimeout as sleep } from 'node:timers/promises';
import { CancelledError } from './cancelledError.js';
import { buildTorrentFile, FALLBACK_TRACKERS, MagnetError, parseMagnet } from './magnet.js';
import { fetchMetadataFromPeer } from './peer/fetchMetadata.js';
import { parseTorrentFile } from './torrentFile.js';
import { announce as defaultAnnounce } from './trackers/announce.js';
import { generatePeerId } from './trackers/peerId.js';

// Turns a magnet link into .torrent bytes.
//
// 1. Exact sources first (xs=, the URL of the .torrent itself, as webtorrent.io gives): the
//    file is downloaded and kept only if its info-hash is the magnet's, so a source can be
//    wrong but never trusted.
// 2. Otherwise peers: every tracker of the magnet plus FALLBACK_TRACKERS is asked in parallel,
//    then up to `parallelPeers` peers at a time are asked for the metadata, and the first copy
//    matching the info-hash wins. Many peers never answer (browsers on WebRTC, clients that do
//    not serve metadata), so many are tried at once with a short `peerTimeoutMs`. Trackers are
//    asked again every `reannounceMs` while no peer delivered, until `timeoutMs`.
//
// No DHT (BEP 5): a magnet whose swarm is only reachable through the DHT fails with a clear
// error.
//
// Results are cached by info-hash, so inspect then start fetch the metadata once. The shared
// fetch belongs to the resolver, not to its first caller: each caller can only cancel its own
// wait, and the fetch stops once nobody waits for it any more.
export function createMagnetResolver({
  announceFn = defaultAnnounce,
  fetchMetadataFn = fetchMetadataFromPeer,
  generatePeerIdFn = generatePeerId,
  fetchImpl = fetch,
  parseTorrentFileFn = parseTorrentFile,
  timeoutMs = 45000,
  exactSourceTimeoutMs = 15000,
  peerTimeoutMs = 5000,
  parallelPeers = 30,
  reannounceMs = 10000,
  trackerPort = 6881,
} = {}) {
  // info-hash -> { promise, controller, waiters, settled }
  const cache = new Map();

  function resolve(uri, { signal } = {}) {
    const magnet = parseMagnet(uri);
    let entry = cache.get(magnet.infoHash);

    if (!entry) {
      const controller = new AbortController();
      const created = { controller, waiters: 0, settled: false };
      created.promise = fetchTorrent(magnet, controller.signal);
      created.promise.then(
        () => {
          created.settled = true;
        },
        () => {
          // A failure is not cached: the next attempt may find peers.
          created.settled = true;
          forget(magnet.infoHash, created);
        },
      );
      cache.set(magnet.infoHash, created);
      entry = created;
    }

    return wait(magnet.infoHash, entry, signal);
  }

  // The caller's view of the shared fetch: rejects with CancelledError when its own signal
  // aborts, without touching the fetch the other callers still wait for.
  function wait(infoHash, entry, signal) {
    if (signal?.aborted) {
      return Promise.reject(new CancelledError());
    }

    entry.waiters += 1;

    return new Promise((resolveWait, rejectWait) => {
      let left = false;

      function leave() {
        if (left) {
          return false;
        }

        left = true;
        signal?.removeEventListener('abort', onAbort);
        entry.waiters -= 1;

        if (entry.waiters === 0 && !entry.settled) {
          entry.controller.abort();
          forget(infoHash, entry);
        }

        return true;
      }

      function onAbort() {
        if (leave()) {
          rejectWait(new CancelledError());
        }
      }

      signal?.addEventListener('abort', onAbort, { once: true });
      entry.promise.then(
        (bytes) => leave() && resolveWait(bytes),
        (err) => leave() && rejectWait(err),
      );
    });
  }

  function forget(infoHash, entry) {
    if (cache.get(infoHash) === entry) {
      cache.delete(infoHash);
    }
  }

  async function fetchTorrent(magnet, signal) {
    const failures = [];

    for (const url of magnet.exactSources) {
      const bytes = await fromExactSource(url, magnet.infoHash, signal, failures);

      if (bytes) {
        return bytes;
      }
    }

    const infoHash = Buffer.from(magnet.infoHash, 'hex');
    const peerId = generatePeerIdFn();
    const trackers = [...new Set([...magnet.trackers, ...FALLBACK_TRACKERS])];
    const deadline = Date.now() + timeoutMs;
    const tried = new Set();
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

    const causes = [...new Set(failures)].slice(0, 6).join(' | ');

    throw new MagnetError(
      (peersSeen === 0
        ? `No peer found for magnet ${magnet.infoHash} on ${trackers.length} tracker(s) in ${Math.round(timeoutMs / 1000)} s (no DHT support)`
        : `None of the ${peersSeen} peer(s) found sent the metadata of magnet ${magnet.infoHash} in ${Math.round(timeoutMs / 1000)} s`) +
        (causes ? `: ${causes}` : ''),
    );
  }

  // The .torrent an exact source points at, or null (reason added to `failures`).
  async function fromExactSource(url, expectedHash, signal, failures) {
    if (!/^https?:\/\//i.test(url)) {
      return null;
    }

    try {
      const response = await fetchImpl(url, {
        signal: AbortSignal.any([signal, AbortSignal.timeout(exactSourceTimeoutMs)]),
      });

      if (!response.ok) {
        failures.push(`${url}: HTTP ${response.status}`);

        return null;
      }

      const bytes = Buffer.from(await response.arrayBuffer());
      const { infoHash } = parseTorrentFileFn(bytes);

      if (infoHash.toLowerCase() !== expectedHash) {
        failures.push(`${url}: info-hash ${infoHash} is not the magnet's`);

        return null;
      }

      return bytes;
    } catch (err) {
      throwIfAborted(signal);
      failures.push(`${url}: ${err.message}`);

      return null;
    }
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
