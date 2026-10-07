<?php

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'torrent_client_url' => env('TORRENT_CLIENT_URL', 'http://client-torrent:7881'),
    'stream' => [
        // Above the Client Torrent STREAM_STALL_TIMEOUT_MS (60 s), in microseconds.
        'rw_timeout_us' => 90_000_000,
        // ffprobe may have to wait for the pieces holding the moov.
        'probe_timeout_s' => 300,
    ],
    'hls' => [
        'segment_duration' => 6,
        'bandwidth' => 1_700_000,
    ],
];
