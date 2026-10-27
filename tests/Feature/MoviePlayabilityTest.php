<?php

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Models\Movie;

test('a playable movie stays playable while its torrent is downloading', function () {
    $movie = Movie::factory()->make([
        'download_status' => DownloadStatus::Downloading,
        'conversion_status' => ConversionStatus::Playable,
    ]);

    expect($movie->isPlayable())->toBeTrue();
});

test('a converted movie stays playable while its torrent is downloading', function () {
    $movie = Movie::factory()->make([
        'download_status' => DownloadStatus::Downloading,
        'conversion_status' => ConversionStatus::Converted,
    ]);

    expect($movie->isPlayable())->toBeTrue();
});

test('a playable movie is unavailable while its files are being purged', function () {
    $movie = Movie::factory()->make([
        'download_status' => DownloadStatus::Purging,
        'conversion_status' => ConversionStatus::Playable,
    ]);

    expect($movie->isPlayable())->toBeFalse();
});

test('a converted movie is unavailable while its files are being purged', function () {
    $movie = Movie::factory()->make([
        'download_status' => DownloadStatus::Purging,
        'conversion_status' => ConversionStatus::Converted,
    ]);

    expect($movie->isPlayable())->toBeFalse();
});
