// What can be read of each file, from qBittorrent's piece states (2 = downloaded and
// verified). Only those bytes are ever served: a partial file has holes that read as zeros
// without any error (ADR-0007).

const DONE = 2;

export function createPieceMap({ pieceLength, totalLength, states = [] }) {
  const isDone = (piece) => states[piece] === DONE;
  const pieceOf = (absoluteOffset) => Math.floor(absoluteOffset / pieceLength);

  // Bytes readable from `position` (relative to the file) without hitting a missing piece.
  function availableFrom(file, position) {
    const fileEnd = file.torrentOffset + file.length;
    const start = file.torrentOffset + position;

    if (position >= file.length) {
      return 0;
    }

    let piece = pieceOf(start);

    while (piece * pieceLength < fileEnd && isDone(piece)) {
      piece += 1;
    }

    return Math.max(0, Math.min(piece * pieceLength, fileEnd, totalLength) - start);
  }

  // [start, end) ranges of the file that are readable, merged.
  function fileRanges(file) {
    const ranges = [];

    if (file.length === 0) {
      return ranges;
    }

    const fileEnd = file.torrentOffset + file.length;

    for (let piece = pieceOf(file.torrentOffset); piece * pieceLength < fileEnd; piece += 1) {
      if (!isDone(piece)) {
        continue;
      }

      const start = Math.max(piece * pieceLength, file.torrentOffset) - file.torrentOffset;
      const end = Math.min((piece + 1) * pieceLength, fileEnd) - file.torrentOffset;
      const last = ranges.at(-1);

      if (last && last[1] === start) {
        last[1] = end;
      } else {
        ranges.push([start, end]);
      }
    }

    return ranges;
  }

  function downloadedBytes(file) {
    return fileRanges(file).reduce((sum, [start, end]) => sum + end - start, 0);
  }

  function countDone(pieces) {
    return pieces.filter(isDone).length;
  }

  // Same layout as BEP3's bitfield message: piece 0 is the high bit of the first byte.
  function bitfieldBase64() {
    const bytes = Buffer.alloc(Math.ceil(states.length / 8));

    states.forEach((state, piece) => {
      if (state === DONE) {
        bytes[piece >> 3] |= 0x80 >> (piece & 7);
      }
    });

    return bytes.toString('base64');
  }

  return {
    availableFrom,
    contiguousBytesFromStart: (file) => availableFrom(file, 0),
    fileRanges,
    downloadedBytes,
    countDone,
    bitfieldBase64,
  };
}
