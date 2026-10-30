<?php

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Http\Controllers\MovieConversionController;
use App\Jobs\ConvertMovie;
use App\Jobs\DownloadMovie;
use App\Models\Movie;
use Illuminate\Support\Facades\Queue;

test('a pending movie is queued and a conversion job is dispatched', function () {
    Queue::fake();

    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Pending)
        ->create();

    app(MovieConversionController::class)->store($movie);

    $movie->refresh();

    expect($movie->conversion_status)->toBe(ConversionStatus::Queued);

    Queue::assertPushed(ConvertMovie::class, function (ConvertMovie $job) use ($movie): bool {
        return $job->movieId === $movie->id;
    });
});

test('preparing a movie already downloaded does not start a download again', function () {
    Queue::fake();

    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Pending)
        ->create([
            'torrent_url' => 'https://example.com/movie.torrent',
            'download_status' => DownloadStatus::Completed,
            'filename' => 'movie.mkv',
        ]);

    app(MovieConversionController::class)->store($movie);

    Queue::assertNotPushed(DownloadMovie::class);
    Queue::assertPushed(ConvertMovie::class, fn (ConvertMovie $job): bool => $job->movieId === $movie->id);
});

test('an unsupported torrent cannot be prepared again', function () {
    Queue::fake();

    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Pending)
        ->create([
            'torrent_url' => 'https://example.com/movie.torrent',
            'download_status' => DownloadStatus::Unsupported,
            'download_error' => 'The torrent contains no supported MP4 or MKV video file.',
        ]);

    app(MovieConversionController::class)->store($movie);

    expect($movie->refresh()->conversion_status)->toBe(ConversionStatus::Pending);
    Queue::assertNotPushed(DownloadMovie::class);
    Queue::assertNotPushed(ConvertMovie::class);
});
