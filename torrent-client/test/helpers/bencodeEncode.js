// Minimal bencode encoder used only to build fixtures in tests. Deliberately
// separate from src/bencode.js (which only needs to decode for #7) so tests
// don't validate the decoder against itself.
export function encode(value) {
  if (Buffer.isBuffer(value)) {
    return Buffer.concat([Buffer.from(`${value.length}:`), value]);
  }
  if (typeof value === 'string') {
    const bytes = Buffer.from(value, 'utf8');
    return Buffer.concat([Buffer.from(`${bytes.length}:`), bytes]);
  }
  if (typeof value === 'number') {
    return Buffer.from(`i${value}e`);
  }
  if (Array.isArray(value)) {
    return Buffer.concat([Buffer.from('l'), ...value.map(encode), Buffer.from('e')]);
  }
  if (value && typeof value === 'object') {
    const keys = Object.keys(value).sort();
    const parts = [Buffer.from('d')];
    for (const key of keys) {
      parts.push(encode(key), encode(value[key]));
    }
    parts.push(Buffer.from('e'));
    return Buffer.concat(parts);
  }
  throw new TypeError(`Cannot bencode value of type ${typeof value}`);
}
