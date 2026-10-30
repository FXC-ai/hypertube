<?php

namespace App\Rules;

use App\Support\PublicHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Client Torrent fetches what an imported link points to from inside the Docker
 * network. Checking the address of a link is not enough (a public URL can redirect to an
 * internal one, a .torrent can list internal trackers), so imports are limited to the
 * trusted sites of config('movies.import_trusted_hosts'):
 *
 * - an http(s) .torrent link must come from one of them;
 * - a magnet keeps its exact sources and web seeds (xs, as, ws) only on those sites, and
 *   its trackers only over UDP to a public host (an HTTP tracker could redirect);
 *   withoutUntrustedLinks() drops the rest before the link is stored. Without a tracker
 *   left, the Client Torrent asks its own public fallback trackers.
 */
class TorrentUrl implements ValidationRule
{
    private const PATTERN = '/^(https?:\/\/[^\s]+\/[^\s?]+\.torrent(\?[^\s]*)?|magnet:\?xt=urn:btih:[a-fA-F0-9]{40}(?:&[^\s]*)?|magnet:\?xt=urn:btih:[a-z2-7]{32}(?:&[^\s]*)?)$/i';

    /**
     * Magnet parameters naming a link the Client Torrent would contact, plain or numbered
     * (tr.1, xs.2...).
     */
    private const MAGNET_LINK_PARAMETERS = ['tr', 'xs', 'as', 'ws'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The torrent URL must be a magnet link or an http(s) .torrent link.');

            return;
        }

        if (! self::isMagnet($value) && ! self::isTrusted($value)) {
            $fail('The torrent URL must come from a trusted source: '.implode(', ', self::trustedHosts()).'.');
        }
    }

    /**
     * The magnet without the links the Client Torrent must not contact; any other link
     * is returned unchanged.
     */
    public static function withoutUntrustedLinks(string $value): string
    {
        if (! self::isMagnet($value)) {
            return $value;
        }

        [$prefix, $query] = array_pad(explode('?', $value, 2), 2, '');

        $kept = array_filter(explode('&', $query), function (string $pair): bool {
            [$key, $url] = array_pad(explode('=', $pair, 2), 2, '');
            $name = strtolower(explode('.', urldecode($key))[0]);

            return match (true) {
                ! in_array($name, self::MAGNET_LINK_PARAMETERS, true) => true,
                $name === 'tr' => self::isPublicUdpTracker(urldecode($url)),
                default => self::isTrusted(urldecode($url)),
            };
        });

        return $prefix.'?'.implode('&', $kept);
    }

    /**
     * @return list<string>
     */
    private static function trustedHosts(): array
    {
        return array_values(config('movies.import_trusted_hosts', []));
    }

    private static function isMagnet(string $value): bool
    {
        return str_starts_with(strtolower($value), 'magnet:');
    }

    private static function isTrusted(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        foreach (self::trustedHosts() as $trusted) {
            if ($host === $trusted || str_ends_with($host, '.'.$trusted)) {
                return true;
            }
        }

        return false;
    }

    private static function isPublicUdpTracker(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'udp'
            && is_string($host)
            && PublicHost::isAllowed($host);
    }
}
