export class TrackerError extends Error {
  constructor(message, { trackerUrl } = {}) {
    super(trackerUrl ? `${message} (${trackerUrl})` : message);
    this.name = 'TrackerError';
    this.trackerUrl = trackerUrl;
  }
}
