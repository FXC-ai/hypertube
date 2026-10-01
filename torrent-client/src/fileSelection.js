// Which files of a torrent we actually want. Everything is guessed from file extensions: at
// inspection time no byte of the content has been downloaded yet.

export class FileSelectionError extends Error {
  constructor(message) {
    super(message);
    this.name = 'FileSelectionError';
  }
}

const VIDEO_CONTAINERS = new Map([
  ['mp4', 'mp4'],
  ['m4v', 'mp4'],
  ['mov', 'mp4'],
  ['mkv', 'matroska'],
  ['webm', 'matroska'],
  ['ogv', 'ogg'],
  ['avi', 'avi'],
]);
const SUBTITLE_EXTENSIONS = new Set(['srt', 'vtt', 'ass', 'ssa', 'sub']);

export function classifyFile(path) {
  const dot = path.lastIndexOf('.');
  const extension = dot === -1 ? '' : path.slice(dot + 1).toLowerCase();

  if (VIDEO_CONTAINERS.has(extension)) {
    return { kind: 'video', container: VIDEO_CONTAINERS.get(extension) };
  }

  if (SUBTITLE_EXTENSIONS.has(extension)) {
    return { kind: 'subtitle', container: null };
  }

  return { kind: 'other', container: null };
}

// Suggestion: the largest video plus every non-empty subtitle (external subtitles sit next to
// MP4s, and Laravel can still untick them; archive.org ships empty ones). A torrent without any video keeps every file, so a
// caller that sends no fileIndexes never ends up downloading nothing.
export function inspectTorrent(torrent) {
  const files = torrent.files.map((file, index) => ({
    index,
    path: file.path,
    length: file.length,
    ...classifyFile(file.path),
  }));

  let mainVideoIndex = null;

  for (const file of files) {
    if (
      file.kind === 'video' &&
      (mainVideoIndex === null || file.length > files[mainVideoIndex].length)
    ) {
      mainVideoIndex = file.index;
    }
  }

  for (const file of files) {
    file.suggested =
      mainVideoIndex === null ||
      file.index === mainVideoIndex ||
      (file.kind === 'subtitle' && file.length > 0);
  }

  return {
    infoHash: torrent.infoHash,
    name: torrent.name,
    pieceLength: torrent.pieceLength,
    totalPieces: torrent.pieces.length,
    totalLength: torrent.totalLength,
    mainVideoIndex,
    files,
  };
}

// Checks what can be checked before the .torrent is known (the HTTP handler answers 400).
export function validateFileIndexesShape(fileIndexes) {
  if (fileIndexes === undefined) {
    return;
  }

  if (!Array.isArray(fileIndexes) || fileIndexes.length === 0) {
    throw new FileSelectionError('fileIndexes must be a non-empty array');
  }

  if (!fileIndexes.every((index) => Number.isInteger(index) && index >= 0)) {
    throw new FileSelectionError('fileIndexes must only contain non-negative integers');
  }

  if (new Set(fileIndexes).size !== fileIndexes.length) {
    throw new FileSelectionError('fileIndexes must not contain duplicates');
  }
}

// Returns the sorted indexes to download: the explicit ones, or the inspection's suggestion.
export function resolveFileIndexes(torrent, fileIndexes) {
  if (fileIndexes === undefined) {
    return inspectTorrent(torrent)
      .files.filter((file) => file.suggested)
      .map((file) => file.index);
  }

  const outOfRange = fileIndexes.filter((index) => index >= torrent.files.length);

  if (outOfRange.length > 0) {
    throw new FileSelectionError(
      `fileIndexes out of range: ${outOfRange.join(', ')} (torrent has ${torrent.files.length} file(s))`,
    );
  }

  return [...fileIndexes].sort((a, b) => a - b);
}
