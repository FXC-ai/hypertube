import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';
import { buildTorrentFile, MagnetError, parseMagnet } from '../src/magnet.js';
import { parseTorrentFile } from '../src/torrentFile.js';
import { encode } from './helpers/bencodeEncode.js';

const EVALUATION_MAGNET =
  'magnet:?xt=urn:btih:79816060ea56d56f2a2148cd45705511079f9bca&dn=TPB.AFK.2013.720p.h264-SimonKlose&tr=udp%3A%2F%2Ftracker.openbittorrent.com%3A80&tr=udp%3A%2F%2Fopen.demonii.com%3A1337';

test('parseMagnet reads the info-hash, the name, the trackers and the web-seeds', () => {
  assert.deepEqual(parseMagnet(EVALUATION_MAGNET), {
    infoHash: '79816060ea56d56f2a2148cd45705511079f9bca',
    name: 'TPB.AFK.2013.720p.h264-SimonKlose',
    trackers: ['udp://tracker.openbittorrent.com:80', 'udp://open.demonii.com:1337'],
    webSeeds: [],
    exactSources: [],
  });
  assert.deepEqual(
    parseMagnet(
      'magnet:?xt=urn:btih:ABCDEF0123456789ABCDEF0123456789ABCDEF01&ws=https%3A%2F%2Fseed.test%2F',
    ).webSeeds,
    ['https://seed.test/'],
  );
});

test('parseMagnet accepts a base32 info-hash and rejects a magnet without one', () => {
  // base32 of 20 bytes 0x00..0x13
  const hex = Buffer.from(Array.from({ length: 20 }, (_, i) => i)).toString('hex');
  assert.equal(parseMagnet('magnet:?xt=urn:btih:AAAQEAYEAUDAOCAJBIFQYDIOB4IBCEQT').infoHash, hex);

  assert.throws(() => parseMagnet('magnet:?dn=nothing'), MagnetError);
  assert.throws(() => parseMagnet('magnet:?xt=urn:btih:not-a-hash'), MagnetError);
  assert.throws(() => parseMagnet('https://example.test/x.torrent'), MagnetError);
});

test('buildTorrentFile wraps the info dictionary into a .torrent with the same info-hash', () => {
  const info = encode({
    length: 5,
    name: 'movie.mkv',
    'piece length': 16384,
    pieces: Buffer.alloc(20, 7),
  });
  const bytes = buildTorrentFile(info, {
    trackers: ['udp://a.test:1', 'udp://b.test:2'],
    webSeeds: ['https://seed.test/'],
  });
  const torrent = parseTorrentFile(bytes);

  assert.equal(torrent.infoHash, createHash('sha1').update(info).digest('hex'));
  assert.equal(torrent.name, 'movie.mkv');
  assert.equal(torrent.announce, 'udp://a.test:1');
  assert.deepEqual(torrent.urlList, ['https://seed.test/']);
});

test('parseMagnet reads the exact sources (xs), the URL of the .torrent itself', () => {
  const magnet = parseMagnet(
    'magnet:?xt=urn:btih:dd8255ecdc7ca55fb0bbf81323d87062db1f6d1c&xs=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2Fbig-buck-bunny.torrent',
  );

  assert.deepEqual(magnet.exactSources, ['https://webtorrent.io/torrents/big-buck-bunny.torrent']);
});
