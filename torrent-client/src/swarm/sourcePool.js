// Peers and web-seeds with their health. A source that cannot be reached (refused, connect
// timeout, fetch failed) `maxConnectionFailures` times in a row is set aside for `cooldownMs`,
// then gets one more chance: web-seeds are often the only source for archive.org, so nobody is
// written off for a network hiccup. A source that sends `maxHashFailures` corrupted pieces is
// banned for good. Failing a piece for another reason (peer without that piece, HTTP 404) does
// not count against the source.
export function createSourcePool(
  initialSources,
  { maxConnectionFailures = 3, maxHashFailures = 2, cooldownMs = 30000, now = Date.now } = {},
) {
  const sources = [];
  let cursor = 0;

  add(initialSources);

  function keyOf(source) {
    return source.kind === 'peer' ? `${source.peer.ip}:${source.peer.port}` : source.baseUrl;
  }

  // Returns how many sources were new (a re-announce often lists known peers again).
  function add(newSources) {
    let added = 0;

    for (const source of newSources) {
      const key = keyOf(source);

      if (sources.some((known) => known.key === key)) {
        continue;
      }

      sources.push({
        ...source,
        key,
        connectionFailures: 0,
        hashFailures: 0,
        suspendedUntil: 0,
        banned: false,
        lastError: null,
      });
      added += 1;
    }

    return added;
  }

  function isActive(source) {
    return !source.banned && source.suspendedUntil <= now();
  }

  // The active source that failed this piece the least, rotating among ties so the load
  // spreads. `failuresBySource` maps a source key to how often it failed this piece.
  function pick(failuresBySource) {
    const active = sources.filter(isActive);

    if (active.length === 0) {
      return null;
    }

    let best = null;
    let bestFailures = Infinity;

    for (let i = 0; i < active.length; i += 1) {
      const source = active[(cursor + i) % active.length];
      const failures = failuresBySource.get(source.key) ?? 0;

      if (failures < bestFailures) {
        best = source;
        bestFailures = failures;
      }
    }

    cursor += 1;

    return best;
  }

  function reportSuccess(source) {
    source.connectionFailures = 0;
  }

  function reportFailure(source, err) {
    source.lastError = err.message;

    if (err.hashMismatch) {
      source.hashFailures += 1;
      source.banned = source.hashFailures >= maxHashFailures;
    }

    if (err.connectionFailure) {
      source.connectionFailures += 1;

      if (source.connectionFailures >= maxConnectionFailures) {
        source.suspendedUntil = now() + cooldownMs;
        // back from cooldown, a single new connection failure sets it aside again
        source.connectionFailures = maxConnectionFailures - 1;
      }
    }
  }

  function counts() {
    const active = sources.filter(isActive).length;

    return { active, dropped: sources.length - active };
  }

  // When the next set-aside source comes back, or null if none will.
  function nextReactivationAt() {
    const pending = sources
      .filter((source) => !source.banned && source.suspendedUntil > now())
      .map((source) => source.suspendedUntil);

    return pending.length > 0 ? Math.min(...pending) : null;
  }

  function describeDropped() {
    return sources
      .filter((source) => !isActive(source))
      .map(
        (source) =>
          `${source.key} (${source.banned ? 'banned' : 'cooling down'}): ${source.lastError}`,
      );
  }

  return { add, pick, reportSuccess, reportFailure, counts, nextReactivationAt, describeDropped };
}
