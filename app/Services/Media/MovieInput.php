<?php

namespace App\Services\Media;

/**
 * Where ffprobe/ffmpeg read a movie from: a file on disk, or the Client Torrent streaming
 * URL while the movie is still downloading (ADR-0007).
 */
final readonly class MovieInput
{
    public function __construct(
        public string $location,
        public bool $isStream,
    ) {}

    /**
     * Options to put BEFORE the input (-i for ffmpeg, before the path for ffprobe).
     *
     * -reconnect_on_http_error 5xx retries a 503 (late piece) and stops on a 410 (download
     * failed); the Client Torrent waits for slow pieces itself, so a short delay is enough.
     *
     * @return list<string>
     */
    public function inputOptions(): array
    {
        if (! $this->isStream) {
            return [];
        }

        return [
            '-rw_timeout', (string) config('media.stream.rw_timeout_us'),
            '-reconnect', '1',
            '-reconnect_on_network_error', '1',
            '-reconnect_on_http_error', '5xx',
            '-reconnect_delay_max', '5',
        ];
    }
}
