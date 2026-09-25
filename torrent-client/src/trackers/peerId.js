import { randomBytes } from 'node:crypto';

// Azureus-style client identifier: -HT0001- (Hypertube, version 0001),
// padded to 20 bytes with random data as BEP20 expects.
const CLIENT_PREFIX = '-HT0001-';

export function generatePeerId() {
  return Buffer.concat([
    Buffer.from(CLIENT_PREFIX, 'ascii'),
    randomBytes(20 - CLIENT_PREFIX.length),
  ]);
}
