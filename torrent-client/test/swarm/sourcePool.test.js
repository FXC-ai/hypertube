import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createSourcePool } from '../../src/swarm/sourcePool.js';

const PEER_A = { kind: 'peer', peer: { ip: '10.0.0.1', port: 6881 } };
const PEER_B = { kind: 'peer', peer: { ip: '10.0.0.2', port: 6881 } };
const SEED = { kind: 'webseed', baseUrl: 'http://seed.test/' };

test('a waited-for piece goes to the source that already delivered, others keep rotating', () => {
  const pool = createSourcePool([PEER_A, PEER_B, SEED]);
  const noFailures = new Map();
  const proven = pool.pick(noFailures);
  pool.reportSuccess(proven);
  pool.reportSuccess(proven);

  for (let i = 0; i < 3; i += 1) {
    assert.equal(pool.pick(noFailures, { preferProven: true }).key, proven.key);
  }

  const rotated = new Set([0, 1, 2].map(() => pool.pick(noFailures).key));
  assert.equal(rotated.size, 3, 'normal pieces still visit every source');
});

test('with nothing proven yet, a waited-for piece goes to a web-seed rather than an untested peer', () => {
  const pool = createSourcePool([PEER_A, PEER_B, SEED]);

  for (let i = 0; i < 3; i += 1) {
    assert.equal(pool.pick(new Map(), { preferProven: true }).kind, 'webseed');
  }
});

test('fewer failures on this piece still wins over a better track record', () => {
  const pool = createSourcePool([PEER_A, SEED]);
  const seed = pool.pick(new Map(), { preferProven: true });
  pool.reportSuccess(seed);

  assert.equal(pool.pick(new Map([[seed.key, 1]]), { preferProven: true }).kind, 'peer');
});

test('peers a tracker lists with an impossible address are never added', () => {
  const pool = createSourcePool([
    PEER_A,
    { kind: 'peer', peer: { ip: '46.166.191.29', port: 1 } },
    { kind: 'peer', peer: { ip: '10.0.0.3', port: 0 } },
    { kind: 'peer', peer: { ip: '0.0.0.0', port: 6881 } },
    { kind: 'peer', peer: { ip: '255.255.255.255', port: 6881 } },
    { kind: 'peer', peer: { ip: '239.1.2.3', port: 6881 } },
  ]);

  assert.deepEqual(pool.counts(), { active: 1, dropped: 0 });
});

test('a source with every slot taken is skipped until a slot is released', () => {
  const pool = createSourcePool([PEER_A, SEED], { capacityOf: () => 1 });
  const first = pool.pick(new Map());
  pool.acquire(first);
  const second = pool.pick(new Map());
  pool.acquire(second);

  assert.notEqual(first.key, second.key);
  assert.equal(pool.pick(new Map()), null, 'both sources are busy');

  pool.release(first);
  assert.equal(pool.pick(new Map()).key, first.key);
});
