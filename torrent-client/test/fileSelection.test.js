import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  classifyFile,
  FileSelectionError,
  inspectTorrent,
  resolveFileIndexes,
  validateFileIndexesShape,
} from '../src/fileSelection.js';

function fakeTorrent(files) {
  return {
    name: 'some_item',
    infoHash: 'ab'.repeat(20),
    pieceLength: 16,
    pieces: ['h0', 'h1', 'h2'],
    totalLength: files.reduce((sum, f) => sum + f.length, 0),
    files,
  };
}

test('classifyFile: kind and container come from the extension, case-insensitively', () => {
  assert.deepEqual(classifyFile('Movie.MP4'), { kind: 'video', container: 'mp4' });
  assert.deepEqual(classifyFile('dir/Movie.mkv'), { kind: 'video', container: 'matroska' });
  assert.deepEqual(classifyFile('Movie.webm'), { kind: 'video', container: 'matroska' });
  assert.deepEqual(classifyFile('Movie.ogv'), { kind: 'video', container: 'ogg' });
  assert.deepEqual(classifyFile('Movie.en.srt'), { kind: 'subtitle', container: null });
  assert.deepEqual(classifyFile('item_meta.sqlite'), { kind: 'other', container: null });
  assert.deepEqual(classifyFile('README'), { kind: 'other', container: null });
});

test('inspectTorrent: suggests the largest video and every subtitle, nothing else', () => {
  const inspection = inspectTorrent(
    fakeTorrent([
      { path: 'item_meta.sqlite', length: 20 },
      { path: 'Movie.ogv', length: 300 },
      { path: 'Movie.mp4', length: 900 },
      { path: 'Movie.en.srt', length: 5 },
      { path: 'Movie.fr.vtt', length: 6 },
    ]),
  );

  assert.equal(inspection.mainVideoIndex, 2);
  assert.equal(inspection.totalPieces, 3);
  assert.deepEqual(
    inspection.files.filter((f) => f.suggested).map((f) => f.index),
    [2, 3, 4],
  );
  assert.deepEqual(inspection.files[1], {
    index: 1,
    path: 'Movie.ogv',
    fileName: 'Movie.ogv',
    length: 300,
    kind: 'video',
    container: 'ogg',
    suggested: false,
  });
});

test('inspectTorrent: an empty subtitle file is not suggested', () => {
  const inspection = inspectTorrent(
    fakeTorrent([
      { path: 'Movie.mp4', length: 900 },
      { path: 'Movie.en.srt', length: 0 },
      { path: 'Movie.fr.srt', length: 5 },
    ]),
  );

  assert.deepEqual(
    inspection.files.filter((f) => f.suggested).map((f) => f.index),
    [0, 2],
  );
});

test('inspectTorrent: without any video file, every file is suggested', () => {
  const inspection = inspectTorrent(
    fakeTorrent([
      { path: 'ubuntu.iso', length: 100 },
      { path: 'SHA256SUMS', length: 1 },
    ]),
  );

  assert.equal(inspection.mainVideoIndex, null);
  assert.ok(inspection.files.every((f) => f.suggested));
});

test('validateFileIndexesShape: rejects anything but a non-empty list of distinct non-negative integers', () => {
  assert.doesNotThrow(() => validateFileIndexesShape(undefined));
  assert.doesNotThrow(() => validateFileIndexesShape([0, 2]));

  for (const bad of [[], 'x', [1, 1], [-1], [1.5], ['0'], {}]) {
    assert.throws(() => validateFileIndexesShape(bad), FileSelectionError, JSON.stringify(bad));
  }
});

test('resolveFileIndexes: explicit indexes are kept and sorted, absent means the suggestion', () => {
  const torrent = fakeTorrent([
    { path: 'a.sqlite', length: 1 },
    { path: 'Movie.mkv', length: 50 },
  ]);

  assert.deepEqual(resolveFileIndexes(torrent, [1, 0]), [0, 1]);
  assert.deepEqual(resolveFileIndexes(torrent, undefined), [1]);
  assert.throws(() => resolveFileIndexes(torrent, [2]), /out of range/);
});

test('inspectTorrent: each file gives the flat name it will have in outputDir', () => {
  const inspection = inspectTorrent(
    fakeTorrent([
      { path: 'M(1931)/M.1931.mp4', length: 900 },
      { path: 'Subs/English.srt', length: 5 },
      { path: 'Extras/english.srt', length: 6 },
    ]),
  );

  assert.deepEqual(
    inspection.files.map((f) => f.fileName),
    ['M.1931.mp4', 'English.srt', 'english (2).srt'],
  );
});
