import { TrackerError } from './errors.js';
import { announceHttpTracker } from './httpTracker.js';
import { announceUdpTracker } from './udpTracker.js';

// Tries each tracker URL in order (deduplicated) and returns the result of
// the first successful announce. A tracker that times out, errors, or uses
// an unsupported scheme (e.g. wss://, used by browser-facing WebTorrent
// swarms -- see docs/testing-torrent-sources.md) is skipped rather than
// failing the whole call, so one bad tracker never blocks the others.
export async function announce(trackerUrls, params, options = {}) {
  const errors = [];

  for (const trackerUrl of dedupe(trackerUrls)) {
    const announcer = pickAnnouncer(trackerUrl, options);

    if (!announcer) {
      errors.push(
        new TrackerError(
          'Unsupported tracker scheme (only http(s):// and udp:// are implemented)',
          { trackerUrl },
        ),
      );
      continue;
    }

    try {
      return await announcer(trackerUrl, params, options);
    } catch (err) {
      errors.push(err);
    }
  }

  throw new TrackerError(
    `All ${errors.length} tracker(s) failed: ${errors.map((err) => err.message).join('; ')}`,
  );
}

export function flattenTrackerUrls(torrent) {
  const urls = [];

  if (torrent.announce) {
    urls.push(torrent.announce);
  }

  if (torrent.announceList) {
    for (const tier of torrent.announceList) {
      urls.push(...tier);
    }
  }

  return dedupe(urls);
}

function pickAnnouncer(trackerUrl, options) {
  const scheme = trackerUrl.split(':', 1)[0];

  if (scheme === 'http' || scheme === 'https') {
    return options.httpAnnouncer ?? announceHttpTracker;
  }

  if (scheme === 'udp') {
    return options.udpAnnouncer ?? announceUdpTracker;
  }

  return null;
}

function dedupe(urls) {
  return [...new Set(urls)];
}
