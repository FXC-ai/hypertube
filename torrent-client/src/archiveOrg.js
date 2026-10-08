import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';

// archive.org publishes the SHA-1 of every file of an item. It is the fallback check for a
// piece that can no longer match its hash because archive.org rewrote another file sharing it
// (see "Stale pieces" in swarm/downloadTorrent.js): the file we want is checked whole instead.

// The archive.org item behind a torrent, or null. Its web-seeds point at archive.org
// (archive.org/download/ or a storage node, ia800000.us.archive.org/.../items/), and an
// archive.org torrent is named after its item.
export function archiveItemOf(torrent) {
  const onArchiveOrg = (torrent.urlList ?? []).some((url) => {
    try {
      const { hostname } = new URL(url);

      return hostname === 'archive.org' || hostname.endsWith('.archive.org');
    } catch {
      return false;
    }
  });

  return onArchiveOrg && torrent.name ? torrent.name : null;
}

// Map of file path inside the item -> SHA-1 (hex), from archive.org's metadata API.
export async function fetchArchiveFileHashes(item, { fetchImpl = fetch } = {}) {
  const url = `https://archive.org/metadata/${encodeURIComponent(item)}/files`;
  let response;

  try {
    response = await fetchImpl(url);
  } catch (err) {
    throw new Error(`Cannot fetch ${url}: ${err.message}`);
  }

  if (!response.ok) {
    throw new Error(`Cannot fetch ${url}: HTTP ${response.status}`);
  }

  const { result = [] } = await response.json();

  return new Map(result.filter((file) => file.sha1).map((file) => [file.name, file.sha1]));
}

export function sha1OfFile(path) {
  return new Promise((resolve, reject) => {
    const hash = createHash('sha1');
    createReadStream(path)
      .on('data', (chunk) => hash.update(chunk))
      .on('error', reject)
      .on('end', () => resolve(hash.digest('hex')));
  });
}
