<?php

namespace App\Support;

final class PublicHost
{
    /**
     * Whether the Client Torrent may contact this host: false when it is, or resolves to,
     * a loopback, private, link-local or reserved address (the Docker network, the cloud
     * metadata service). A name that resolves to nothing cannot be reached from the Docker
     * network either, so it is let through: the download simply fails.
     */
    public static function isAllowed(string $host): bool
    {
        $host = trim(strtolower($host), '[]');

        if ($host === '') {
            return false;
        }

        // The Client Torrent (Node) reads a host the WHATWG way: it percent-decodes it, and a
        // host whose last label is a number is an IPv4 address in decimal, octal or hex
        // (127.0.0.1%2E, %31%32%37.0.0.1, 0x7f.1 and 127.1 all mean 127.0.0.1), while PHP
        // takes them as names that resolve to nothing. Only the plain a.b.c.d form is
        // accepted for an address, and never a percent-encoded host.
        if (str_contains($host, '%')) {
            return false;
        }

        // Node drops the trailing dot of a host (127.0.0.1. is 127.0.0.1): every check below
        // runs on the host without it.
        $host = rtrim($host, '.');
        $labels = explode('.', $host);

        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels)) === 1
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
