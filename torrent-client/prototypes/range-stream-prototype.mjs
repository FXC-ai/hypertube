// Prototype of GET /downloads/:id/files/:index (ADR-0007), without BitTorrent.
//
// Simulates a multi-file torrent: the files of LAYOUT are put back to back and cut into 1 MiB
// pieces, like BitTorrent does, so a piece can hold the end of one file and the start of the
// next. Pieces "arrive" at RATE pieces/s: priority pieces first, then the lowest missing one.
// One file of the layout is served over HTTP with Range support; a request on missing bytes
// makes the pieces covering them priority 1 and waits for them, as the real endpoint will.
// Every request is logged with the torrent pieces it touched, so we can see what
// ffprobe/ffmpeg really ask for.
//
// usage: node range-stream-prototype.mjs <layout> <served index> [rate pieces/s] [port] [never-piece]
//   layout: comma-separated entries, either a real file path or name:size for a filler file
//           (random bytes), e.g. "meta.sqlite:300000,movie.mkv,subs.srt:700000"
//   never-piece: a torrent piece that never arrives (stall timeout), or arrives after
//           LATE_MS ms when that environment variable is set.
// env: STALL_TIMEOUT_MS (default 60000), PIECE_BYTES (default 1 MiB)

import { createReadStream, statSync } from 'node:fs';
import { createServer } from 'node:http';

const [layoutArg, servedArg, rateArg = '2', portArg = '8790', neverArg] = process.argv.slice(2);
const PIECE = Number(process.env.PIECE_BYTES ?? 1024 * 1024);
const RATE = Number(rateArg);
const STALL_TIMEOUT_MS = Number(process.env.STALL_TIMEOUT_MS ?? 60000);
const NEVER = neverArg === undefined ? null : Number(neverArg);
const LATE_MS = process.env.LATE_MS === undefined ? null : Number(process.env.LATE_MS);

let offset = 0;
const files = layoutArg.split(',').map((entry) => {
  const [name, fillerSize] = entry.split(':');
  const length = fillerSize === undefined ? statSync(name).size : Number(fillerSize);
  const file = {
    name,
    path: fillerSize === undefined ? name : null,
    length,
    torrentOffset: offset,
  };
  offset += length;

  return file;
});
const totalLength = offset;
const served = files[Number(servedArg)];
const pieceCount = Math.ceil(totalLength / PIECE);
const have = new Array(pieceCount).fill(false);
const priority = new Set();
const t0 = Date.now();
const elapsed = () => ((Date.now() - t0) / 1000).toFixed(1).padStart(5) + 's';
// file byte -> torrent piece
const pieceOf = (fileByte) => Math.floor((served.torrentOffset + fileByte) / PIECE);

function filesInPiece(p) {
  const start = p * PIECE;
  const end = Math.min(totalLength, start + PIECE);

  return files
    .filter((f) => f.torrentOffset < end && f.torrentOffset + f.length > start)
    .map((f) => f.name);
}

function describePieces(first, last) {
  return [first, last]
    .filter((p, i) => i === 0 || p !== first)
    .map(
      (p) => `${p}${filesInPiece(p).length > 1 ? ` (shared: ${filesInPiece(p).join(' + ')})` : ''}`,
    )
    .join(' .. ');
}

if (NEVER !== null && LATE_MS !== null) {
  setTimeout(() => {
    have[NEVER] = true;
    console.log(`${elapsed()} piece ${NEVER} finally arrives`);
  }, LATE_MS);
}

// The fake swarm: one piece every 1/RATE s, priority pieces first, then the lowest missing.
setInterval(() => {
  const next =
    [...priority].find((i) => !have[i] && i !== NEVER) ??
    have.findIndex((ok, i) => !ok && i !== NEVER);

  if (next === -1 || next === undefined) {
    return;
  }

  have[next] = true;
  priority.delete(next);
}, 1000 / RATE);

async function waitFor(pieceIndex) {
  const waitedSince = Date.now();

  while (!have[pieceIndex]) {
    if (Date.now() - waitedSince > STALL_TIMEOUT_MS) {
      throw new Error(`piece ${pieceIndex} not received for ${STALL_TIMEOUT_MS} ms`);
    }

    priority.add(pieceIndex);
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
}

createServer(async (req, res) => {
  const size = served.length;
  const match = /bytes=(\d*)-(\d*)/.exec(req.headers.range ?? '');
  let start = 0;
  let end = size - 1;

  if (match && match[1] === '') {
    start = size - Number(match[2]); // bytes=-N : the last N bytes
  } else if (match) {
    start = Number(match[1]);
    end = match[2] === '' ? size - 1 : Math.min(Number(match[2]), size - 1);
  }

  if (start >= size) {
    res.writeHead(416, { 'Content-Range': `bytes */${size}` }).end();
    console.log(`${elapsed()} Range=${req.headers.range} -> 416`);

    return;
  }

  // Wait for the first piece before sending headers: a stall here can still be a clean 503.
  const asked = Date.now();
  const missingAtRequest = have.slice(pieceOf(start), pieceOf(end) + 1).filter((ok) => !ok).length;

  try {
    await waitFor(pieceOf(start));
  } catch (err) {
    res
      .writeHead(503, { 'Content-Type': 'application/json' })
      .end(JSON.stringify({ error: err.message }));
    console.log(`${elapsed()} Range=${req.headers.range ?? '(none)'} -> 503 ${err.message}`);

    return;
  }

  res.writeHead(match ? 206 : 200, {
    'Accept-Ranges': 'bytes',
    'Content-Type': served.name.endsWith('.mkv') ? 'video/x-matroska' : 'video/mp4',
    'Content-Length': end - start + 1,
    ...(match ? { 'Content-Range': `bytes ${start}-${end}/${size}` } : {}),
  });
  console.log(
    `${elapsed()} Range=${req.headers.range ?? '(none)'} -> ${match ? 206 : 200} ` +
      `file bytes [${start}-${end}] = torrent pieces ${describePieces(pieceOf(start), pieceOf(end))}, ` +
      `${missingAtRequest} missing, first byte after ${Date.now() - asked} ms`,
  );

  // Then stream piece by piece, waiting for each one; the client may close early (seek).
  let closed = false;
  res.on('close', () => {
    closed = true;
  });

  for (let p = pieceOf(start); p <= pieceOf(end) && !closed; p += 1) {
    try {
      await waitFor(p);
    } catch (err) {
      console.log(`${elapsed()}   stalled mid-response (${err.message}), connection cut`);
      res.destroy();

      return;
    }

    // Only the served file's bytes of this piece: the other file's part is never sent.
    const from = Math.max(start, p * PIECE - served.torrentOffset);
    const to = Math.min(end, (p + 1) * PIECE - served.torrentOffset - 1);
    await new Promise((resolve, reject) => {
      const stream = createReadStream(served.path, { start: from, end: to });
      stream.on('end', resolve).on('error', reject).pipe(res, { end: false });
    }).catch(() => {});
  }

  res.end();
}).listen(Number(portArg), '127.0.0.1', () => {
  console.log(
    `${elapsed()} torrent: ${totalLength} bytes, ${pieceCount} pieces of ${PIECE} bytes, ${RATE} pieces/s`,
  );

  for (const f of files) {
    const first = Math.floor(f.torrentOffset / PIECE);
    const last = Math.floor((f.torrentOffset + f.length - 1) / PIECE);
    console.log(
      `        ${f === served ? '*' : ' '} ${f.name}: torrent bytes [${f.torrentOffset}-${f.torrentOffset + f.length - 1}], pieces ${first}-${last}`,
    );
  }
});
