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
