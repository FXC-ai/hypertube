import { once } from 'node:events';
import { open } from 'node:fs/promises';
import { extname } from 'node:path';

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

// Serves [range] of a file that may still be downloading. `source`:
// - file: computeFileLayout entry, path: absolute path on disk
// - pieceLength, availability: see pieceAvailability.js
// - endedState(): null while the job runs, else { status, error }
// A missing piece is claimed urgent (with `readaheadBytes` after it) and waited for, up to
// `stallTimeoutMs` per piece: before headers that is a 503 (retry) or 410 (job over); after
// headers the connection is cut, and the reader's next request gets the status code.
export async function streamFile(req, res, source, { readaheadBytes, stallTimeoutMs }) {
  const { file, path, pieceLength, availability, endedState } = source;
  const range = parseRange(req.headers.range, file.length);

  if (range?.unsatisfiable) {
    res.writeHead(416, { 'Content-Range': `bytes */${file.length}` });
    res.end();

    return;
  }

  const start = range ? range.start : 0;
  const end = range ? range.end : file.length - 1;
  const pieceOf = (fileByte) => Math.floor((file.torrentOffset + fileByte) / pieceLength);
  const lastPiece = pieceOf(Math.max(start, end));
  let closed = false;
  let release = () => {};
  let handle;

  res.on('close', () => {
    closed = true;
    release();
  });

  function prioritize(fromPiece) {
    release();
    const windowEnd = pieceOf(
      Math.min(end, (fromPiece + 1) * pieceLength - file.torrentOffset + readaheadBytes),
    );
    const wanted = [];

    for (let p = fromPiece; p <= Math.min(lastPiece, windowEnd); p += 1) {
      if (!availability.has(p)) {
        wanted.push(p);
      }
    }

    release = availability.claimUrgent(wanted);
  }

  try {
    if (file.length > 0) {
      prioritize(pieceOf(start));
      await waitForPiece(availability, pieceOf(start), endedState, stallTimeoutMs, () => closed);
    }
  } catch (err) {
    release();
    sendError(res, err);

    return;
  }

  res.writeHead(range ? 206 : 200, {
    'Accept-Ranges': 'bytes',
    'Content-Type': CONTENT_TYPES[extname(file.path).toLowerCase()] ?? 'application/octet-stream',
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
      const piece = pieceOf(position);

      if (!availability.has(piece)) {
        prioritize(piece);
        await waitForPiece(availability, piece, endedState, stallTimeoutMs, () => closed);
      }

      // Up to the end of this piece (or of the range), in chunks, honouring backpressure.
      const pieceEnd = Math.min(end, (piece + 1) * pieceLength - file.torrentOffset - 1);

      while (position <= pieceEnd && !closed) {
        const length = Math.min(READ_CHUNK_BYTES, pieceEnd - position + 1);
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
    release();
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

// Resolves once `pieceIndex` is verified; rejects with StreamGoneError when the job ends
// without it, or StreamStalledError after `stallTimeoutMs`.
export function waitForPiece(
  availability,
  pieceIndex,
  endedState,
  stallTimeoutMs,
  isClosed = () => false,
) {
  if (availability.has(pieceIndex)) {
    return Promise.resolve();
  }

  return new Promise((resolve, reject) => {
    const timer = setTimeout(
      () =>
        finish(new StreamStalledError(`piece ${pieceIndex} not received for ${stallTimeoutMs} ms`)),
      stallTimeoutMs,
    );
    const poll = setInterval(check, 250);
    const unsubscribe = availability.onPiece((p) => {
      if (p === pieceIndex) {
        finish();
      }
    });

    function check() {
      const ended = endedState();

      if (availability.has(pieceIndex)) {
        finish();
      } else if (ended) {
        finish(new StreamGoneError(ended.status, ended.error));
      } else if (isClosed()) {
        finish(new StreamStalledError('client went away'));
      }
    }

    function finish(err) {
      clearTimeout(timer);
      clearInterval(poll);
      unsubscribe();

      if (err) {
        reject(err);
      } else {
        resolve();
      }
    }

    check();
  });
}
