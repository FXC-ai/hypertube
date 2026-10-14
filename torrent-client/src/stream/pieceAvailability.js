// Which pieces of a job are verified and on disk, who is waiting for which, and in what order
// the swarm should fetch the rest. Shared by the download manager (marks pieces as they are
// verified), the streaming endpoint (waits for pieces, claims urgent ones) and downloadTorrent
// (asks rank() which piece to fetch next).

export const PRIORITY = { URGENT: 0, BOOSTED: 1, NORMAL: 2 };

export function createPieceAvailability({ pieceCount, pieceLength, totalLength }) {
  const have = new Uint8Array(pieceCount);
  const listeners = new Set();
  const urgentClaims = new Map(); // piece -> number of open requests waiting on it
  let boosted = new Set();
  let count = 0;

  function has(pieceIndex) {
    return have[pieceIndex] === 1;
  }

  function mark(pieceIndex) {
    if (has(pieceIndex)) {
      return;
    }

    have[pieceIndex] = 1;
    count += 1;

    for (const listener of [...listeners]) {
      listener(pieceIndex);
    }
  }

  // Calls `listener(pieceIndex)` for every newly verified piece; returns the unsubscribe.
  function onPiece(listener) {
    listeners.add(listener);

    return () => listeners.delete(listener);
  }

  // A streaming request needs these pieces now. Returns the function that releases the claim.
  function claimUrgent(pieceIndexes) {
    for (const pieceIndex of pieceIndexes) {
      urgentClaims.set(pieceIndex, (urgentClaims.get(pieceIndex) ?? 0) + 1);
    }

    let released = false;

    return () => {
      if (released) {
        return;
      }

      released = true;

      for (const pieceIndex of pieceIndexes) {
        const left = urgentClaims.get(pieceIndex) - 1;

        if (left === 0) {
          urgentClaims.delete(pieceIndex);
        } else {
          urgentClaims.set(pieceIndex, left);
        }
      }
    };
  }

  function setBoosted(pieceIndexes) {
    boosted = new Set(pieceIndexes);
  }

  function rank(pieceIndex) {
    if (urgentClaims.has(pieceIndex)) {
      return PRIORITY.URGENT;
    }

    return boosted.has(pieceIndex) ? PRIORITY.BOOSTED : PRIORITY.NORMAL;
  }

  // BitTorrent "bitfield" layout: the high bit of the first byte is piece 0.
  function bitfieldBase64() {
    const bytes = Buffer.alloc(Math.ceil(pieceCount / 8));

    for (let i = 0; i < pieceCount; i += 1) {
      if (have[i]) {
        bytes[i >> 3] |= 0x80 >> (i & 7);
      }
    }

    return bytes.toString('base64');
  }

  // Byte ranges of `file` (a computeFileLayout entry) whose pieces are all verified, as
  // [start, endExclusive] pairs relative to the file, merged and sorted.
  function fileRanges(file) {
    const ranges = [];

    if (file.length === 0) {
      return ranges;
    }

    const firstPiece = Math.floor(file.torrentOffset / pieceLength);
    const lastPiece = Math.floor((file.torrentOffset + file.length - 1) / pieceLength);

    for (let p = firstPiece; p <= lastPiece; p += 1) {
      if (!has(p)) {
        continue;
      }

      const start = Math.max(0, p * pieceLength - file.torrentOffset);
      const end = Math.min(
        file.length,
        Math.min((p + 1) * pieceLength, totalLength) - file.torrentOffset,
      );
      const last = ranges.at(-1);

      if (last && last[1] === start) {
        last[1] = end;
      } else {
        ranges.push([start, end]);
      }
    }

    return ranges;
  }

  function contiguousBytesFromStart(file) {
    const [first] = fileRanges(file);

    return first && first[0] === 0 ? first[1] : 0;
  }

  return {
    has,
    mark,
    onPiece,
    claimUrgent,
    setBoosted,
    rank,
    bitfieldBase64,
    fileRanges,
    contiguousBytesFromStart,
    count: () => count,
  };
}
