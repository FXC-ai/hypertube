import { createHash } from 'node:crypto';
import { open } from 'node:fs/promises';
import { join } from 'node:path';
import { CancelledError } from './cancelledError.js';
import {
  computeFileLayout,
  computeOverlaps,
  computePieceRanges,
  computeWantedPieces,
} from './torrentLayout.js';

// Resume without any saved state: the files already in `outputDir` are the state. Every
// wanted piece is read back and kept only if its SHA-1 matches. Holes in a sparse file read as
// zeros and fail the hash like any corrupted piece. A piece shared with an unchosen file is
// never valid: those bytes were never written, so the piece cannot be verified from disk.
export async function recheckPieces(torrent, { outputDir, fileIndexes, onPiece, signal }) {
  const fileLayout = computeFileLayout(torrent);
  const pieceRanges = computePieceRanges(torrent);
  const wantedFileIndexes = new Set(fileIndexes ?? fileLayout.map((file) => file.index));
  const wantedPieces = computeWantedPieces(fileLayout, pieceRanges, wantedFileIndexes);
  const handles = new Map();
  const valid = new Set();

  async function handleFor(file) {
    if (!handles.has(file.path)) {
      handles.set(
        file.path,
        await open(join(outputDir, file.path), 'r').catch((err) => {
          if (err.code === 'ENOENT') {
            return null;
          }

          throw err;
        }),
      );
    }

    return handles.get(file.path);
  }

  try {
    for (const [i, pieceIndex] of wantedPieces.entries()) {
      if (signal?.aborted) {
        throw new CancelledError();
      }

      const { offset, length } = pieceRanges[pieceIndex];

      if (
        await isPieceOnDisk(torrent.pieces[pieceIndex], computeOverlaps(fileLayout, offset, length))
      ) {
        valid.add(pieceIndex);
      }

      onPiece?.({
        checked: i + 1,
        total: wantedPieces.length,
        pieceIndex,
        valid: valid.has(pieceIndex),
      });
    }
  } finally {
    await Promise.all([...handles.values()].filter(Boolean).map((handle) => handle.close()));
  }

  return valid;

  async function isPieceOnDisk(expectedHash, overlaps) {
    const hash = createHash('sha1');

    for (const overlap of overlaps) {
      if (!wantedFileIndexes.has(overlap.file.index)) {
        return false;
      }

      const handle = await handleFor(overlap.file);

      if (!handle) {
        return false;
      }

      const chunk = Buffer.alloc(overlap.length);
      const { bytesRead } = await handle.read(chunk, 0, overlap.length, overlap.fileOffset);

      if (bytesRead !== overlap.length) {
        return false;
      }

      hash.update(chunk);
    }

    return hash.digest('hex') === expectedHash;
  }
}
