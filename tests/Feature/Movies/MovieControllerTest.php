<?php

use App\Enums\ExternalSubtitleStatus;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use App\Enums\DownloadStatus;
use App\Enums\ConversionStatus;

test('the movie page exposes only prepared external subtitles through public URLs', function () {
    config(['services.omdb.key' => null]);

    $user = User::factory()->create();
    $movie = Movie::factory()->create([
        'external_subtitles_status' => ExternalSubtitleStatus::Ready,
        'external_subtitles' => [
            [
                'file_index' => 3,
                'file_name' => 'Movie.english.srt',
                'language' => 'eng',
                'output_file' => 'subtitles/external_00_eng.vtt',
            ],
            [
                'file_index' => 4,
                'file_name' => 'Movie.french.srt',
                'language' => 'fra',
                'output_file' => null,
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('movies.show', $movie));

    $response->assertOk()->assertInertia(fn(Assert $page) => $page
        ->component('movies/show')
        ->where('moviePageData.external_subtitles_status', ExternalSubtitleStatus::Ready->value)
        ->has('moviePageData.external_subtitles', 1)
        ->where('moviePageData.external_subtitles.0', [
            'id' => 3,
            'label' => 'Movie.english.srt — External',
            'language' => 'eng',
            'url' => Storage::disk('public')->url(
                "movies/{$movie->id}/subtitles/external_00_eng.vtt"
            ),
        ]));

    $tracks = $response->inertiaProps('moviePageData.external_subtitles');

    expect(json_encode($tracks, JSON_THROW_ON_ERROR))
        ->not->toContain(Storage::disk('public')->path("movies/{$movie->id}"));
});

test('the movie page present an error message if the download fail', function () {
    $user = User::factory()->create();
    $movie = Movie::factory()->create(
        [
            'download_status' => DownloadStatus::Failed,
            'download_error' => 'Client Torrent answered 502: HTTP 403',
        ]
    );
    $response = $this->actingAs($user)->get(route('movies.show', $movie));

    $response->assertOk()->assertInertia(fn(Assert $page) => $page
        ->component('movies/show')
        ->where('moviePageData.download_status', DownloadStatus::Failed->value)
        ->where(
            'moviePageData.download_error',
            'This movie is not available.',
        ));
});

test('the movie page present an error message if the conversion fail', function () {
    $user = User::factory()->create();
    $movie = Movie::factory()->create([
        'conversion_status' => ConversionStatus::Failed,
        'conversion_error' => 'ffmpeg exited with code 1: technical details...',
    ]);
    $response = $this->actingAs($user)->get(route('movies.show', $movie));

    $response->assertOk()->assertInertia(fn(Assert $page) => $page
        ->component('movies/show')
        ->where('moviePageData.conversion_error', 'The conversion failed.'));
});
