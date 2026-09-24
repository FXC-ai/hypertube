// Shared across the peer and swarm layers so the HTTP layer (#11) can tell
// "the caller cancelled this" apart from "this genuinely failed" with a
// single instanceof check, regardless of which layer raised it.
export class CancelledError extends Error {
  constructor(message = 'Cancelled') {
    super(message);
    this.name = 'CancelledError';
  }
}
