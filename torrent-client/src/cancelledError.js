// Lets callers tell "cancelled" from "failed" with one instanceof check.
export class CancelledError extends Error {
  constructor(message = 'Cancelled') {
    super(message);
    this.name = 'CancelledError';
  }
}
