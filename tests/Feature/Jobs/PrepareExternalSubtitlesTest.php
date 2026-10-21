<?php

use App\Enums\DownloadStatus;
use App\Enums\ExternalSubtitleStatus;
use App\Jobs\PrepareExternalSubtitles;
use App\Models\Movie;
use Illuminate\Support\Facades\Storage;

test('it prepares every valid external subtitle and finishes ready', function () {
    Storage::fake('public');

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Completed,
        'external_subtitles_status' => ExternalSubtitleStatus::Queued,
        'external_subtitles' => [
            [
                'file_index' => 3,
                'file_name' => 'Movie.english.srt',
                'language' => 'eng',
                'output_file' => null,
            ],
            [
                'file_index' => 4,
                'file_name' => 'Movie.french.vtt',
                'language' => 'fra',
                'output_file' => null,
            ],
        ],
    ]);

    Storage::disk('public')->put("movies/{$movie->id}/Movie.english.srt", validSrt());
    Storage::disk('public')->put("movies/{$movie->id}/Movie.french.vtt", validVtt());

    app()->call([new PrepareExternalSubtitles($movie->id), 'handle']);

    $movie->refresh();

    expect($movie->external_subtitles_status)->toBe(ExternalSubtitleStatus::Ready)
        ->and(array_column($movie->external_subtitles, 'output_file'))->toBe([
            'subtitles/external_00_eng.vtt',
            'subtitles/external_01_fra.vtt',
        ]);

    Storage::disk('public')->assertExists([
        "movies/{$movie->id}/subtitles/external_00_eng.vtt",
        "movies/{$movie->id}/subtitles/external_01_fra.vtt",
    ]);
});

test('it ignores an invalid subtitle without losing valid outputs', function () {
    Storage::fake('public');

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Completed,
        'external_subtitles_status' => ExternalSubtitleStatus::Queued,
        'external_subtitles' => [
            [
                'file_index' => 8,
                'file_name' => 'valid.srt',
                'language' => 'eng',
                'output_file' => null,
            ],
            [
                'file_index' => 9,
                'file_name' => 'invalid.srt',
                'language' => 'und',
                'output_file' => null,
            ],
        ],
    ]);

    Storage::disk('public')->put("movies/{$movie->id}/valid.srt", validSrt());
    Storage::disk('public')->put("movies/{$movie->id}/invalid.srt", 'not a subtitle');

    app()->call([new PrepareExternalSubtitles($movie->id), 'handle']);

    $movie->refresh();

    expect($movie->external_subtitles_status)->toBe(ExternalSubtitleStatus::Ready)
        ->and($movie->external_subtitles[0]['output_file'])->toBe('subtitles/external_00_eng.vtt')
        ->and($movie->external_subtitles[1]['output_file'])->toBeNull();

    Storage::disk('public')->assertExists("movies/{$movie->id}/subtitles/external_00_eng.vtt");
    Storage::disk('public')->assertMissing("movies/{$movie->id}/subtitles/external_01_und.vtt");
});

test('it refuses to prepare subtitles before the download is completed', function () {
    Storage::fake('public');

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Downloading,
        'external_subtitles_status' => ExternalSubtitleStatus::Queued,
        'external_subtitles' => [[
            'file_index' => 2,
            'file_name' => 'Movie.english.srt',
            'language' => 'eng',
            'output_file' => null,
        ]],
    ]);

    Storage::disk('public')->put("movies/{$movie->id}/Movie.english.srt", validSrt());

    app()->call([new PrepareExternalSubtitles($movie->id), 'handle']);

    expect($movie->refresh()->external_subtitles_status)->toBe(ExternalSubtitleStatus::Queued);
    Storage::disk('public')->assertMissing("movies/{$movie->id}/subtitles/external_00_eng.vtt");
});

test('a duplicate job does not convert prepared subtitles again', function () {
    Storage::fake('public');

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Completed,
        'external_subtitles_status' => ExternalSubtitleStatus::Queued,
        'external_subtitles' => [[
            'file_index' => 6,
            'file_name' => 'Movie.english.srt',
            'language' => 'eng',
            'output_file' => null,
        ]],
    ]);

    Storage::disk('public')->put("movies/{$movie->id}/Movie.english.srt", validSrt());

    $job = new PrepareExternalSubtitles($movie->id);
    app()->call([$job, 'handle']);

    $outputPath = "movies/{$movie->id}/subtitles/external_00_eng.vtt";
    Storage::disk('public')->put($outputPath, 'sentinel');

    app()->call([$job, 'handle']);

    expect(Storage::disk('public')->get($outputPath))->toBe('sentinel')
        ->and($movie->refresh()->external_subtitles_status)->toBe(ExternalSubtitleStatus::Ready);
});

function validSrt(): string
{
    return "1\n00:00:00,000 --> 00:00:01,000\nHello from SRT.\n";
}

function validVtt(): string
{
    return "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nBonjour depuis VTT.\n";
}
