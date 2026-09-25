// Pure byte-range math shared by the peer-wire write path (#10) and the
// web-seed read path (#12): both need to know which file(s) a chunk of the
// concatenated piece stream belongs to. BitTorrent lays out a multi-file
// torrent's pieces and BEP19 web-seed ranges identically -- every file
// concatenated back to back, in `files` order.

export function computeFileLayout(torrent) {
  let offset = 0;

  return torrent.files.map((file) => {
    const entry = { path: file.path, length: file.length, torrentOffset: offset };
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

// A byte range [rangeStart, rangeStart + rangeLength) in the concatenated
// stream can straddle a file boundary. Returns one entry per file the range
// actually overlaps:
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
