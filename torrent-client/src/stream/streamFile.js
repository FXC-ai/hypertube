import { once } from 'node:events';
import { open } from 'node:fs/promises';
import { extname } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

const CONTENT_TYPES = {
  '.mp4': 'video/mp4',
  '.m4v': 'video/mp4',
  '.mov': 'video/quicktime',
  '.mkv': 'video/x-matroska',
  '.webm': 'video/webm',
  '.ogv': 'video/ogg',
  '.avi': 'video/x-msvideo',
  '.vtt': 'text/vtt',
  '.srt': 'application/x-subrip',
};
const READ_CHUNK_BYTES = 256 * 1024;
const RETRY_AFTER_S = '5';

export class StreamStalledError extends Error {}

// The job ended (failed or cancelled) without these bytes: they will never come.
export class StreamGoneError extends Error {
  constructor(status, error) {
    super(`download ${status}${error ? `: ${error}` : ''}`);
    this.status = status;
    this.error = error;
  }
}

// One "bytes=" range out of a Range header, for a file of `size` bytes. Returns null when
// the whole file is asked for (no header, or a form we do not support, which RFC 9110 lets
// us ignore), { unsatisfiable: true } for a range outside the file, else { start, end }
// with `end` inclusive.
export function parseRange(header, size) {
  const match = /^bytes=(\d*)-(\d*)$/.exec(header ?? '');

  if (!match || (match[1] === '' && match[2] === '')) {
    return null;
  }

  if (match[1] === '') {
    const suffix = Number(match[2]);

    return suffix === 0 || size === 0
      ? { unsatisfiable: true }
      : { start: Math.max(0, size - suffix), end: size - 1 };
  }

  const start = Number(match[1]);
  const end = match[2] === '' ? size - 1 : Math.min(Number(match[2]), size - 1);

  return start >= size || end < start ? { unsatisfiable: true } : { start, end };
}

// Serves [range] of a file qBittorrent may still be writing, never a byte of a piece it has
// not verified. `source`:
// - file: computeFileLayout entry, path: absolute path on disk
// - pieceMap(): the job's latest piece map (refreshed by the download manager's poll)
// - endedState(): null while the job runs, else { status, error }
// Missing bytes are waited for, up to `stallTimeoutMs` without progress: before headers that
// is a 503 (retry) or 410 (job over); after headers the connection is cut, and the reader's
// next request gets the status code. qBittorrent has no per-piece priority in its API: it
// downloads in order (plus the first and last pieces), so reading forward is what is fast.
export async function streamFile(req, res, source, { stallTimeoutMs, pollIntervalMs = 250 }) {
  const { file, path, pieceMap, endedState } = source;
  const range = parseRange(req.headers.range, file.length);

  if (range?.unsatisfiable) {
    res.writeHead(416, { 'Content-Range': `bytes */${file.length}` });
    res.end();

    return;
  }

  const start = range ? range.start : 0;
  const end = range ? range.end : file.length - 1;
  let closed = false;
  let handle;

  res.on('close', () => {
    closed = true;
  });

  const waitFor = (position) =>
    waitForBytes(() => pieceMap().availableFrom(file, position), {
      endedState,
      stallTimeoutMs,
      pollIntervalMs,
      isClosed: () => closed,
    });

  try {
    if (file.length > 0) {
      await waitFor(start);
    }
  } catch (err) {
    sendError(res, err);

    return;
  }

  res.writeHead(range ? 206 : 200, {
    'Accept-Ranges': 'bytes',
    'Content-Type':
      CONTENT_TYPES[extname(file.fileName).toLowerCase()] ?? 'application/octet-stream',
    'Content-Length': file.length === 0 ? 0 : end - start + 1,
    ...(range ? { 'Content-Range': `bytes ${start}-${end}/${file.length}` } : {}),
  });

  if (file.length === 0) {
    res.end();

    return;
  }

  try {
    handle = await open(path, 'r');
    let position = start;

    while (position <= end && !closed) {
      const available = await waitFor(position);
      const stop = Math.min(end, position + available - 1);

      // In chunks, honouring backpressure.
      while (position <= stop && !closed) {
        const length = Math.min(READ_CHUNK_BYTES, stop - position + 1);
        const chunk = Buffer.alloc(length);
        const { bytesRead } = await handle.read(chunk, 0, length, position);

        if (bytesRead !== length) {
          throw new Error(`short read at ${position} in ${path}`);
        }

        position += length;

        // 'drain' never comes once the reader has hung up: wait for whichever happens first,
        // or the file handle would never be closed.
        if (!res.write(chunk) && !closed) {
          await Promise.race([once(res, 'drain'), once(res, 'close')]);
        }
      }
    }

    res.end();
  } catch {
    // Headers are gone: the only signal left is a cut connection.
    res.destroy();
  } finally {
    await handle?.close();
  }
}

function sendError(res, err) {
  if (err instanceof StreamGoneError) {
    res.writeHead(410, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: err.status, error: err.error ?? null }));

    return;
  }

  if (err instanceof StreamStalledError) {
    res.writeHead(503, { 'Content-Type': 'application/json', 'Retry-After': RETRY_AFTER_S });
    res.end(JSON.stringify({ error: err.message }));

    return;
  }

  res.destroy(err);
}

// Resolves with the number of readable bytes once there is at least one; rejects with
// StreamGoneError when the job ends first, or StreamStalledError after `stallTimeoutMs`.
export async function waitForBytes(
  available,
  { endedState, stallTimeoutMs, pollIntervalMs = 250, isClosed = () => false },
) {
  const deadline = Date.now() + stallTimeoutMs;

  for (;;) {
    const bytes = available();

    if (bytes > 0) {
      return bytes;
    }

    const ended = endedState();

    if (ended) {
      throw new StreamGoneError(ended.status, ended.error);
    }

    if (isClosed()) {
      throw new StreamStalledError('client went away');
    }

    if (Date.now() >= deadline) {
      throw new StreamStalledError(`no new verified bytes for ${stallTimeoutMs} ms`);
    }

    await sleep(pollIntervalMs);
  }
}
