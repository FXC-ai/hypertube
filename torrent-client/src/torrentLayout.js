// Byte-range math shared by the peer-wire write path and the web-seed read path: a torrent's
// files are concatenated back to back in `files` order before being cut into pieces.

export function computeFileLayout(torrent) {
  let offset = 0;

  return torrent.files.map((file, index) => {
    const entry = { index, path: file.path, length: file.length, torrentOffset: offset };
    offset += file.length;

    return entry;
  });
}

export function computePieceRanges(torrent) {
  const ranges = [];
  let offset = 0;

  for (let i = 0; i < torrent.pieces.length; i += 1) {
    const length = Math.min(torrent.pieceLength, torrent.totalLength - offset);
    ranges.push({ offset, length });
    offset += length;
  }

  return ranges;
}

// A byte range in the concatenated stream can straddle files. Returns one entry per file it
// overlaps:
// - file: the computeFileLayout() entry
// - fileOffset: where this chunk starts within that file
// - length: how many bytes of this chunk
// - rangeOffset: where this chunk starts within [rangeStart, rangeStart + rangeLength)
export function computeOverlaps(fileLayout, rangeStart, rangeLength) {
  const rangeEnd = rangeStart + rangeLength;
  const overlaps = [];

  for (const file of fileLayout) {
    const fileEnd = file.torrentOffset + file.length;
    const overlapStart = Math.max(rangeStart, file.torrentOffset);
    const overlapEnd = Math.min(rangeEnd, fileEnd);

    if (overlapStart >= overlapEnd) {
      continue;
    }

    overlaps.push({
      file,
      fileOffset: overlapStart - file.torrentOffset,
      length: overlapEnd - overlapStart,
      rangeOffset: overlapStart - rangeStart,
    });
  }

  return overlaps;
}

// Indexes of the pieces that overlap at least one wanted file, in ascending order. A piece
// straddling a wanted and an unwanted file is still needed: its hash covers the whole piece.
export function computeWantedPieces(fileLayout, pieceRanges, wantedFileIndexes) {
  const wantedFiles = fileLayout.filter((file) => wantedFileIndexes.has(file.index));
  const wanted = [];

  for (let i = 0; i < pieceRanges.length; i += 1) {
    const { offset, length } = pieceRanges[i];

    if (computeOverlaps(wantedFiles, offset, length).length > 0) {
      wanted.push(i);
    }
  }

  return wanted;
}
