<?php

use App\Data\MediaStream;
use App\Data\SelectedTracks;
use App\Enums\DownloadStatus;
use App\Models\Movie;
use App\Services\Media\HlsCommandBuilder;
use App\Services\Media\MovieInput;
use App\Services\Media\MovieInputResolver;
use Illuminate\Support\Facades\Storage;

test('a movie still downloading is read through the Client Torrent streaming URL', function () {
    config(['media.torrent_client_url' => 'http://client-torrent:7881']);
    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Downloading,
        'download_id' => 'job-1',
        'download_file_index' => 10,
    ]);

    $input = app(MovieInputResolver::class)->resolve($movie);

    expect($input->isStream)->toBeTrue()
        ->and($input->location)->toBe('http://client-torrent:7881/downloads/job-1/files/10');
});

test('a movie whose download is over, or that never had one, is read from disk', function (DownloadStatus $status) {
    Storage::fake('public');
    $movie = Movie::factory()->create([
        'filename' => 'movie.mp4',
        'download_status' => $status,
        'download_id' => 'job-1',
        'download_file_index' => 10,
    ]);

    $input = app(MovieInputResolver::class)->resolve($movie);

    expect($input->isStream)->toBeFalse()
        ->and($input->location)->toBe(Storage::disk('public')->path("movies/{$movie->id}/movie.mp4"));
})->with([DownloadStatus::Completed, DownloadStatus::Pending, DownloadStatus::Failed]);

test('ffmpeg always gets -xerror, and the stream options go before -i only for a URL', function () {
    $tracks = new SelectedTracks(
        video: new MediaStream(index: 0, type: 'video', codec: 'h264', language: 'und', title: '', disposition: []),
        audio: null,
        subtitles: [],
    );

    $file = app(HlsCommandBuilder::class)->build(new MovieInput('/movies/1/movie.mp4', isStream: false), '/out', $tracks);
    $stream = app(HlsCommandBuilder::class)->build(new MovieInput('http://client-torrent:7881/downloads/j/files/1', isStream: true), '/out', $tracks);

    expect($file)->toContain('-xerror')->not->toContain('-reconnect')
        ->and($stream)->toContain('-xerror');

    $input = array_search('-i', $stream, true);
    expect(array_slice($stream, $input - 10, 10))->toBe([
        '-rw_timeout', '90000000',
        '-reconnect', '1',
        '-reconnect_on_network_error', '1',
        '-reconnect_on_http_error', '5xx',
        '-reconnect_delay_max', '5',
    ])->and($stream[$input + 1])->toBe('http://client-torrent:7881/downloads/j/files/1');
});
