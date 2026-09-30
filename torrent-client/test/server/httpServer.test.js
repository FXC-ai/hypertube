import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { createDownloadManager } from '../../src/server/downloadManager.js';
import { createServer } from '../../src/server/httpServer.js';

const SINTEL = new URL('../fixtures/sintel.webtorrent.io.torrent', import.meta.url);

// Real parsing and routing, no network: fetch always fails, nothing is ever downloaded.
async function withServer(fn) {
  const manager = createDownloadManager({
    fetchImpl: async () => {
      throw new Error('getaddrinfo ENOTFOUND example.test');
    },
  });
  const server = createServer({ manager });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    return await fn(`http://127.0.0.1:${server.address().port}`);
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
}

function postJson(url, body) {
  return fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });
}

test('POST /torrents/inspect lists the files of a real torrent and suggests the video + subtitles', async () => {
  const torrentBase64 = (await readFile(SINTEL)).toString('base64');

  await withServer(async (base) => {
    const res = await postJson(`${base}/torrents/inspect`, { torrentBase64 });
    assert.equal(res.status, 200);
    const inspection = await res.json();

    const main = inspection.files[inspection.mainVideoIndex];
    assert.equal(main.path, 'Sintel.mp4');
    assert.equal(main.container, 'mp4');
    assert.ok(inspection.files.some((f) => f.kind === 'subtitle'));
    assert.ok(inspection.files.some((f) => f.kind === 'other' && !f.suggested));
    for (const file of inspection.files) {
      assert.equal(
        file.suggested,
        file.index === inspection.mainVideoIndex || file.kind === 'subtitle',
        file.path,
      );
    }
    assert.equal(inspection.infoHash.length, 40);
  });
});

test('POST /torrents/inspect: 400 for a malformed torrent, 502 when the torrent URL cannot be fetched', async () => {
  await withServer(async (base) => {
    const malformed = await postJson(`${base}/torrents/inspect`, {
      torrentBase64: Buffer.from('not a torrent').toString('base64'),
    });
    assert.equal(malformed.status, 400);

    const unreachable = await postJson(`${base}/torrents/inspect`, {
      torrentUrl: 'http://example.test/x.torrent',
    });
    assert.equal(unreachable.status, 502);
    assert.match((await unreachable.json()).error, /ENOTFOUND/);

    const invalidJson = await postJson(`${base}/torrents/inspect`, '{');
    assert.equal(invalidJson.status, 400);
  });
});

test('POST /downloads: 400 for malformed fileIndexes or expectedInfoHash', async () => {
  await withServer(async (base) => {
    for (const extra of [
      { fileIndexes: [] },
      { fileIndexes: [0, 0] },
      { fileIndexes: 'all' },
      { expectedInfoHash: 'x' },
    ]) {
      const res = await postJson(`${base}/downloads`, {
        torrentUrl: 'http://example.test/x.torrent',
        outputDir: '/tmp/out',
        ...extra,
      });
      assert.equal(res.status, 400, JSON.stringify(extra));
    }
  });
});
