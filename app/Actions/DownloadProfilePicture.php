<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DownloadProfilePicture
{
    private const int MAX_BYTES = 2 * 1024 * 1024;

    private const int MAX_DIMENSION = 4000;

    /**
     * Store a copy of the picture the given link points to, null when that link is
     * not a picture this application is allowed to keep.
     */
    public function __invoke(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (! $this->isPubliclyReachable($url)) {
            return null;
        }

        $contents = $this->fetch($url);

        if ($contents === null) {
            return null;
        }

        $extension = $this->extensionOf($contents);

        if ($extension === null) {
            return null;
        }

        $path = 'avatars/'.Str::uuid()->toString().'.'.$extension;

        return Storage::disk('public')->put($path, $contents) ? $path : null;
    }

    /**
     * Only a plain http(s) link to a host outside the internal networks: the server
     * fetches this URL, so an internal one would be an entry point.
     */
    private function isPubliclyReachable(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $address = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? $host
            : gethostbyname($host);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $contents = $response->body();

        return ($contents === '' || strlen($contents) > self::MAX_BYTES) ? null : $contents;
    }

    /**
     * The file extension matching the real contents of the image, never the ones the
     * link claims to carry.
     */
    private function extensionOf(string $contents): ?string
    {
        $information = getimagesizefromstring($contents);

        if ($information === false) {
            return null;
        }

        [$width, $height] = $information;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            return null;
        }

        return match ($information['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }
}
