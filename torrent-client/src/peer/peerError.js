// connectionFailure: the peer could not be reached at all (as opposed to failing a piece).
// hashMismatch: the peer sent data that does not match the piece hash; actualHash is its SHA-1.
export class PeerError extends Error {
  constructor(
    message,
    { connectionFailure = false, hashMismatch = false, actualHash = null } = {},
  ) {
    super(message);
    this.name = 'PeerError';
    this.connectionFailure = connectionFailure;
    this.hashMismatch = hashMismatch;
    this.actualHash = actualHash;
  }
}
