import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { parseTorrentFile } from '../../src/torrentFile.js';
import { announce, flattenTrackerUrls } from '../../src/trackers/announce.js';
import { TrackerError } from '../../src/trackers/errors.js';
import { announceHttpTracker } from '../../src/trackers/httpTracker.js';
import { generatePeerId } from '../../src/trackers/peerId.js';
import { announceUdpTracker } from '../../src/trackers/udpTracker.js';

function loadFixture(name) {
  const path = fileURLToPath(new URL(`../fixtures/${name}`, import.meta.url));

  return parseTorrentFile(readFileSync(path));
}

function paramsFor(torrent, overrides = {}) {
  return {
    infoHash: Buffer.from(torrent.infoHash, 'hex'),
    peerId: generatePeerId(),
    port: 6881,
    left: torrent.totalLength,
    event: 'started',
    ...overrides,
  };
}

// Real network against tracker.opentrackr.org and Sintel, a heavily seeded torrent, so we
// can assert a real populated swarm.
test('announces to a real UDP tracker for a well-seeded reference torrent and gets real peers back', async () => {
  const torrent = loadFixture('sintel.webtorrent.io.torrent');
  const result = await announceUdpTracker('udp://tracker.opentrackr.org:1337', paramsFor(torrent), {
    timeoutMs: 8000,
  });

  assert.ok(result.seeders > 0, 'expected a well-seeded reference torrent to have seeders');
  assert.ok(result.peers.length > 0, 'expected a non-empty peer list');

  for (const peer of result.peers) {
    assert.match(peer.ip, /^\d+\.\d+\.\d+\.\d+$/);
    // Port 0 does show up for some real peers; this only checks the wire format.
    assert.ok(peer.port >= 0 && peer.port <= 65535);
  }
});

// archive.org does not seed peer-to-peer, so the swarm is near-empty (the one peer that comes
// back is our own announce echoed). This only proves the HTTP tracker round-trip works:
// valid bencode, no "failure reason".
test('announces to the real archive.org HTTP tracker and gets a valid response', async () => {
  const torrent = loadFixture('1953_movie_trailers_starting_monday.archive.org.torrent');
  const result = await announceHttpTracker(torrent.announce, paramsFor(torrent), {
    timeoutMs: 8000,
  });

  assert.equal(typeof result.interval, 'number');
  assert.ok(result.interval > 0);
  assert.ok(Array.isArray(result.peers));
});

test('announce() falls back across the Sintel announce-list and still succeeds', async () => {
  const torrent = loadFixture('sintel.webtorrent.io.torrent');
  const trackerUrls = flattenTrackerUrls(torrent);
  // sanity check on the fixture itself: it must mix schemes we support (udp)
  // with ones we don't (wss), otherwise this test wouldn't exercise the
  // "skip unsupported / failed trackers" fallback path at all.
  assert.ok(trackerUrls.some((url) => url.startsWith('udp://')));
  assert.ok(trackerUrls.some((url) => url.startsWith('wss://')));

  const result = await announce(trackerUrls, paramsFor(torrent), { timeoutMs: 8000 });
  assert.ok(result.peers.length > 0);
});

test('rejects within the configured timeout instead of hanging on an unreachable HTTP tracker', async () => {
  const torrent = loadFixture('1953_movie_trailers_starting_monday.archive.org.torrent');
  const start = Date.now();
  await assert.rejects(
    () =>
      announceHttpTracker('http://192.0.2.1:6969/announce', paramsFor(torrent), {
        timeoutMs: 1000,
      }),
    TrackerError,
  );
  assert.ok(Date.now() - start < 5000);
});

test('rejects within the configured timeout instead of hanging on an unreachable UDP tracker', async () => {
  const torrent = loadFixture('sintel.webtorrent.io.torrent');
  const start = Date.now();
  await assert.rejects(
    () => announceUdpTracker('udp://192.0.2.1:6969', paramsFor(torrent), { timeoutMs: 1000 }),
    TrackerError,
  );
  assert.ok(Date.now() - start < 5000);
});
