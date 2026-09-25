import { randomBytes } from 'node:crypto';

// Azureus-style id: -HT0001- plus 12 random bytes (BEP20).
const CLIENT_PREFIX = '-HT0001-';

export function generatePeerId() {
  return Buffer.concat([
    Buffer.from(CLIENT_PREFIX, 'ascii'),
    randomBytes(20 - CLIENT_PREFIX.length),
  ]);
}
