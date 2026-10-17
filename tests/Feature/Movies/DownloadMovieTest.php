<?php

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Exceptions\TorrentClientException;
use App\Http\Controllers\MovieConversionController;
use App\Jobs\ConvertMovie;
use App\Jobs\DownloadMovie;
use App\Jobs\MonitorDownload;
use App\Models\Movie;
use App\Services\Torrent\TorrentClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const TORRENT_URL = 'https://archive.org/download/item/item_archive.torrent';
const INFO_HASH = '3514d8e28544179d35e8bbc10de6f29108375b7a';

function torrentMovie(array $attributes = []): Movie
{
    return Movie::factory()
        ->withConversionStatus(ConversionStatus::Pending)
        ->create(['torrent_url' => TORRENT_URL, 'filename' => 'placeholder.mp4', ...$attributes]);
}

function fakeClientTorrent(array $downloadResponse = ['id' => 'job-1'], int $downloadStatus = 202): void
{
    Http::fake([
        '*/torrents/inspect' => Http::response([
            'infoHash' => INFO_HASH,
            'mainVideoIndex' => 2,
            'files' => [
                ['index' => 0, 'path' => 'item_meta.sqlite', 'fileName' => 'item_meta.sqlite', 'length' => 20, 'kind' => 'other', 'suggested' => false],
                ['index' => 1, 'path' => 'Movie (1931)/movie.srt', 'fileName' => 'movie.srt', 'length' => 5, 'kind' => 'subtitle', 'suggested' => true],
                ['index' => 2, 'path' => 'Movie (1931)/movie.mp4', 'fileName' => 'movie.mp4', 'length' => 900, 'kind' => 'video', 'suggested' => true],
            ],
        ]),
        '*/downloads' => Http::response($downloadResponse, $downloadStatus),
    ]);
}

test('preparing a movie that has a torrent starts its download instead of a conversion', function () {
    Queue::fake();
    $movie = torrentMovie();

    app(MovieConversionController::class)->store($movie);

    Queue::assertPushed(DownloadMovie::class, fn (DownloadMovie $job): bool => $job->movieId === $movie->id);
    Queue::assertNotPushed(ConvertMovie::class);
});

test('DownloadMovie downloads the suggested files and starts the conversion right away', function () {
    Queue::fake();
    Storage::fake('public');
    fakeClientTorrent();
    $movie = torrentMovie();

    app()->call([new DownloadMovie($movie->id), 'handle']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/downloads')
        && $request['fileIndexes'] === [1, 2]
        && $request['expectedInfoHash'] === INFO_HASH
        && $request['outputDir'] === Storage::disk('public')->path("movies/{$movie->id}"));

    $movie->refresh();
    expect($movie->download_status)->toBe(DownloadStatus::Downloading)
        ->and($movie->download_id)->toBe('job-1')
        ->and($movie->download_file_index)->toBe(2)
        ->and($movie->filename)->toBe('movie.mp4')
        ->and($movie->conversion_status)->toBe(ConversionStatus::Queued);

    Queue::assertPushed(ConvertMovie::class, fn (ConvertMovie $job): bool => $job->movieId === $movie->id);
    Queue::assertPushed(MonitorDownload::class);
});

test('a Client Torrent error marks the download as failed and starts no conversion', function () {
    Queue::fake();
    Storage::fake('public');
    fakeClientTorrent(['error' => 'outputDir is required'], 400);
    $movie = torrentMovie();

    expect(fn () => app()->call([new DownloadMovie($movie->id), 'handle']))
        ->toThrow(TorrentClientException::class, 'outputDir is required');

    $movie->refresh();
    expect($movie->download_status)->toBe(DownloadStatus::Failed)
        ->and($movie->download_error)->toContain('outputDir is required');
    Queue::assertNotPushed(ConvertMovie::class);
});

test('MonitorDownload records the end of the download, or checks again later', function (array|int $answer, ?DownloadStatus $expected) {
    Queue::fake();
    Http::fake(['*/downloads/*' => is_int($answer) ? Http::response(['error' => 'Unknown'], $answer) : Http::response($answer)]);
    $movie = torrentMovie(['download_status' => DownloadStatus::Downloading, 'download_id' => 'job-1']);

    app()->call([new MonitorDownload($movie->id), 'handle']);

    if ($expected === null) {
        expect($movie->refresh()->download_status)->toBe(DownloadStatus::Downloading);
        Queue::assertPushed(MonitorDownload::class);

        return;
    }

    expect($movie->refresh()->download_status)->toBe($expected);
    Queue::assertNotPushed(MonitorDownload::class);
})->with([
    'still downloading' => [['status' => 'downloading', 'error' => null], null],
    'completed' => [['status' => 'completed', 'error' => null], DownloadStatus::Completed],
    'failed' => [['status' => 'failed', 'error' => 'No piece completed for 120s'], DownloadStatus::Failed],
    'unknown to a restarted client' => [404, DownloadStatus::Failed],
]);

test('the streaming URL of a file points at the Client Torrent', function () {
    config(['media.torrent_client_url' => 'http://client-torrent:7881/']);

    expect(app(TorrentClient::class)->fileUrl('job 1', 2))
        ->toBe('http://client-torrent:7881/downloads/job%201/files/2');
});
