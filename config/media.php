<?php

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),

    'torrent_client_url' => env('TORRENT_CLIENT_URL', 'http://client-torrent:7881'),
    'torrent_client_shared_secret' => env('TORRENT_CLIENT_SHARED_SECRET'),
    'stream' => [
        'rw_timeout_us' => 90_000_000,
        'probe_timeout_s' => 300,
    ],

    'hls' => [
        'segment_duration' => 6,
        'bandwidth' => 1_700_000,
        'completion_minimum_ratio' => 0.99,
        'completion_tolerance_segments' => 2,
    ],
];
