<?php

namespace App\Rules;

use App\Support\PublicHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Only an admin may import a link (Gate `import-movies`), but the Client Torrent fetches it
 * from inside the Docker network, so a link to an internal host is never kept, even from
 * an admin:
 *
 * - an http(s) .torrent link to an internal host is refused;
 * - a magnet keeps its trackers, exact sources and web seeds on public hosts, whatever the
 *   site, and withoutInternalLinks() drops the ones pointing to an internal host (dead
 *   trackers often resolve to 127.0.0.1) before the link is stored.
 *
 * Redirects, the trackers listed inside a fetched .torrent and DNS rebinding are not
 * checked here: that is accepted because only an admin can import.
 */
class TorrentUrl implements ValidationRule
{
    private const PATTERN = '/^(https?:\/\/[^\s]+\/[^\s?]+\.torrent(\?[^\s]*)?|magnet:\?xt=urn:btih:[a-fA-F0-9]{40}(?:&[^\s]*)?|magnet:\?xt=urn:btih:[a-z2-7]{32}(?:&[^\s]*)?)$/i';

    /**
     * Magnet parameters the Client Torrent may contact, plain or numbered (tr.1, xs.2...),
     * with the schemes each one accepts: trackers (tr, WebTorrent ones over WebSocket),
     * the .torrent itself (xs, as) and web seeds (ws).
     */
    private const MAGNET_LINK_SCHEMES = [
        'tr' => ['udp', 'http', 'https', 'ws', 'wss'],
        'xs' => ['http', 'https'],
        'as' => ['http', 'https'],
        'ws' => ['http', 'https'],
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The torrent URL must be a magnet link or an http(s) .torrent link.');

            return;
        }

        if (! self::isMagnet($value) && ! self::reachesPublicHost($value, ['http', 'https'])) {
            $fail('The torrent URL may only point to a public host.');
        }
    }

    /**
     * The magnet without the links the Client Torrent must not contact; any other link
     * is returned unchanged.
     */
    public static function withoutInternalLinks(string $value): string
    {
        if (! self::isMagnet($value)) {
            return $value;
        }

        [$prefix, $query] = array_pad(explode('?', $value, 2), 2, '');

        $kept = array_filter(explode('&', $query), function (string $pair): bool {
            [$key, $url] = array_pad(explode('=', $pair, 2), 2, '');
            $name = strtolower(explode('.', urldecode($key))[0]);

            return ! array_key_exists($name, self::MAGNET_LINK_SCHEMES)
                || self::reachesPublicHost(urldecode($url), self::MAGNET_LINK_SCHEMES[$name]);
        });

        return $prefix.'?'.implode('&', $kept);
    }

    private static function isMagnet(string $value): bool
    {
        return str_starts_with(strtolower($value), 'magnet:');
    }

    /**
     * @param  list<string>  $schemes
     */
    private static function reachesPublicHost(string $url, array $schemes): bool
    {
        // PHP and the Client Torrent (Node, WHATWG URL) must agree on the host: Node reads a
        // backslash as a slash, so in https://10.0.0.5\@public.host/ PHP sees public.host
        // but Node connects to 10.0.0.5. Such links, and any user@ before the host, are
        // never needed for a torrent.
        if (str_contains($url, '\\') || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, $schemes, true) && is_string($host) && PublicHost::isAllowed($host);
    }
}
