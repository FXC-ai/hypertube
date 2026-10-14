import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { recheckPieces } from '../src/recheck.js';

const PIECE_LENGTH = 16;

function torrentFor(stream, files) {
  const pieces = [];

  for (let offset = 0; offset < stream.length; offset += PIECE_LENGTH) {
    pieces.push(
      createHash('sha1')
        .update(stream.subarray(offset, offset + PIECE_LENGTH))
        .digest('hex'),
    );
  }

  return { name: 'item', pieceLength: PIECE_LENGTH, pieces, totalLength: stream.length, files };
}

async function withTempDir(fn) {
  const dir = await mkdtemp(join(tmpdir(), 'torrent-recheck-test-'));

  try {
    return await fn(dir);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}

test('keeps only pieces whose bytes on disk match their hash: holes, corruption and missing files fail', async () => {
  const stream = Buffer.from(Array.from({ length: 64 }, (_, i) => i + 1));
  const torrent = torrentFor(stream, [{ path: 'movie.mp4', length: 64 }]);

  await withTempDir(async (outputDir) => {
    // piece 0 good, piece 1 corrupted, piece 2 a hole (zeros, like a sparse file), piece 3 good
    const onDisk = Buffer.from(stream);
    onDisk[20] ^= 0xff;
    onDisk.fill(0, 32, 48);
    await writeFile(join(outputDir, 'movie.mp4'), onDisk);

    const checked = [];
    const valid = await recheckPieces(torrent, {
      outputDir,
      fileIndexes: [0],
      onPiece: ({ checked: n }) => checked.push(n),
    });

    assert.deepEqual([...valid].sort(), [0, 3]);
    assert.deepEqual(checked, [1, 2, 3, 4]);
  });

  await withTempDir(async (outputDir) => {
    const valid = await recheckPieces(torrent, { outputDir, fileIndexes: [0] });
    assert.equal(valid.size, 0);
  });
});

test('a truncated file only validates the pieces it fully contains', async () => {
  const stream = Buffer.from(Array.from({ length: 64 }, (_, i) => i + 1));
  const torrent = torrentFor(stream, [{ path: 'movie.mp4', length: 64 }]);

  await withTempDir(async (outputDir) => {
    await writeFile(join(outputDir, 'movie.mp4'), stream.subarray(0, 40));
    const valid = await recheckPieces(torrent, { outputDir, fileIndexes: [0] });
    assert.deepEqual([...valid].sort(), [0, 1]);
  });
});

test('a piece shared with an unchosen file cannot be verified from disk and is never valid', async () => {
  const stream = Buffer.from(Array.from({ length: 48 }, (_, i) => i + 1));
  // meta.sqlite [0,8) shares piece 0 with movie.mp4 [8,48)
  const torrent = torrentFor(stream, [
    { path: 'meta.sqlite', length: 8 },
    { path: 'movie.mp4', length: 40 },
  ]);

  await withTempDir(async (outputDir) => {
    await writeFile(join(outputDir, 'movie.mp4'), stream.subarray(8));
    // even if the unchosen file happens to be on disk, it is not ours to trust
    await writeFile(join(outputDir, 'meta.sqlite'), stream.subarray(0, 8));

    const valid = await recheckPieces(torrent, { outputDir, fileIndexes: [1] });
    assert.deepEqual([...valid].sort(), [1, 2]);

    const all = await recheckPieces(torrent, { outputDir, fileIndexes: [0, 1] });
    assert.deepEqual([...all].sort(), [0, 1, 2]);
  });
});

test('a file nested in a folder of the torrent is rechecked where it was written, in outputDir', async () => {
  const stream = Buffer.alloc(32, 7);
  const torrent = torrentFor(stream, [{ path: 'M(1931)/movie.mp4', length: 32 }]);

  await withTempDir(async (outputDir) => {
    await writeFile(join(outputDir, 'movie.mp4'), stream);
    const valid = await recheckPieces(torrent, { outputDir, fileIndexes: [0] });
    assert.deepEqual([...valid].sort(), [0, 1]);
  });
});
