<?php

use App\Enums\DownloadStatus;
use App\Enums\ExternalSubtitleStatus;
use App\Jobs\MonitorDownload;
use App\Jobs\PrepareExternalSubtitles;
use App\Models\Movie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('a completed download queues external subtitle preparation exactly once', function () {
    Queue::fake();
    Http::fake(['*/downloads/*' => Http::response(['status' => 'completed', 'error' => null])]);

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Downloading,
        'download_id' => 'download-1',
        'external_subtitles' => [[
            'file_index' => 4,
            'file_name' => 'Movie.english.srt',
            'language' => 'eng',
            'output_file' => null,
        ]],
        'external_subtitles_status' => ExternalSubtitleStatus::Pending,
    ]);

    app()->call([new MonitorDownload($movie->id), 'handle']);
    app()->call([new MonitorDownload($movie->id), 'handle']);

    $movie->refresh();

    expect($movie->download_status)->toBe(DownloadStatus::Completed)
        ->and($movie->external_subtitles_status)->toBe(ExternalSubtitleStatus::Queued);

    Queue::assertPushedTimes(PrepareExternalSubtitles::class, 1);
});

test('a completed download without external subtitles keeps their status at none', function () {
    Queue::fake();
    Http::fake(['*/downloads/*' => Http::response(['status' => 'completed', 'error' => null])]);

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Downloading,
        'download_id' => 'download-2',
        'external_subtitles' => [],
        'external_subtitles_status' => ExternalSubtitleStatus::None,
    ]);

    app()->call([new MonitorDownload($movie->id), 'handle']);

    expect($movie->refresh()->external_subtitles_status)->toBe(ExternalSubtitleStatus::None);
    Queue::assertNotPushed(PrepareExternalSubtitles::class);
});

test('a failed download marks expected external subtitles as failed without preparing them', function (array|int $response) {
    Queue::fake();
    Http::fake([
        '*/downloads/*' => is_int($response)
            ? Http::response(['error' => 'Unknown download'], $response)
            : Http::response($response),
    ]);

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Downloading,
        'download_id' => 'download-3',
        'external_subtitles' => [[
            'file_index' => 5,
            'file_name' => 'Movie.french.srt',
            'language' => 'fra',
            'output_file' => null,
        ]],
        'external_subtitles_status' => ExternalSubtitleStatus::Pending,
    ]);

    app()->call([new MonitorDownload($movie->id), 'handle']);

    $movie->refresh();

    expect($movie->download_status)->toBe(DownloadStatus::Failed)
        ->and($movie->external_subtitles_status)->toBe(ExternalSubtitleStatus::Failed);

    Queue::assertNotPushed(PrepareExternalSubtitles::class);
})->with([
    'client reports a failure' => [['status' => 'failed', 'error' => 'No source available']],
    'client no longer knows the download' => [404],
]);

test('an unknown download without selected subtitles keeps their status at none', function () {
    Queue::fake();
    Http::fake(['*/downloads/*' => Http::response(['error' => 'Unknown download'], 404)]);

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Downloading,
        'download_id' => 'download-4',
        'external_subtitles' => [],
        'external_subtitles_status' => ExternalSubtitleStatus::None,
    ]);

    app()->call([new MonitorDownload($movie->id), 'handle']);

    expect($movie->refresh()->external_subtitles_status)->toBe(ExternalSubtitleStatus::None);
    Queue::assertNotPushed(PrepareExternalSubtitles::class);
});
