// connectionFailure: the peer could not be reached at all (as opposed to failing a piece).
// hashMismatch: the peer sent data that does not match the piece hash.
export class PeerError extends Error {
  constructor(message, { connectionFailure = false, hashMismatch = false } = {}) {
    super(message);
    this.name = 'PeerError';
    this.connectionFailure = connectionFailure;
    this.hashMismatch = hashMismatch;
  }
}
