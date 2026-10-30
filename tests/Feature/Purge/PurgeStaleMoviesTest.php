<?php

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Enums\ExternalSubtitleStatus;
use App\Http\Controllers\MovieConversionController;
use App\Jobs\ConvertMovie;
use App\Jobs\DownloadMovie;
use App\Models\Movie;
use App\Models\User;
use App\Models\WatchedMovie;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    config(['services.omdb.key' => null]);
});

function purgeCandidate(array $attributes = []): Movie
{
    return Movie::factory()->create([
        'download_status' => DownloadStatus::Completed,
        'conversion_status' => ConversionStatus::Converted,
        'last_watched_at' => now()->subDays(40),
        'filename' => 'movie.mp4',
        ...$attributes,
    ]);
}

function storePurgeCandidateFiles(Movie $movie): void
{
    Storage::disk('public')->put("movies/{$movie->id}/movie.mp4", 'video');
    Storage::disk('public')->put(
        "movies/{$movie->id}/hls/attempt/index.m3u8",
        'playlist',
    );
}

test('it purges a completed and converted movie not watched for one month', function () {
    $movie = purgeCandidate();

    storePurgeCandidateFiles($movie);

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    Storage::disk('public')->assertMissing("movies/{$movie->id}/movie.mp4");

    expect($movie->refresh()->download_status)->toBe(DownloadStatus::Pending)
        ->and($movie->conversion_status)->toBe(ConversionStatus::Pending);
});

test('it keeps a recently watched movie even when updated at is old', function () {
    $movie = purgeCandidate([
        'last_watched_at' => now()->subDay(),
        'updated_at' => now()->subDays(40),
    ]);

    storePurgeCandidateFiles($movie);

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    Storage::disk('public')->assertExists("movies/{$movie->id}/movie.mp4");

    expect($movie->refresh()->download_status)->toBe(DownloadStatus::Completed)
        ->and($movie->conversion_status)->toBe(ConversionStatus::Converted);
});

test('it uses updated at when the movie has never been watched', function () {
    $movie = purgeCandidate([
        'last_watched_at' => null,
        'updated_at' => now()->subDays(40),
    ]);

    storePurgeCandidateFiles($movie);

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    Storage::disk('public')->assertMissing("movies/{$movie->id}/movie.mp4");

    expect($movie->refresh()->download_status)->toBe(DownloadStatus::Pending);
});

test('it purges a playable movie that has not been watched for one month', function () {
    $movie = purgeCandidate(['conversion_status' => ConversionStatus::Playable]);

    storePurgeCandidateFiles($movie);

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    Storage::disk('public')->assertMissing("movies/{$movie->id}/movie.mp4");

    expect($movie->refresh()->download_status)->toBe(DownloadStatus::Pending)
        ->and($movie->conversion_status)->toBe(ConversionStatus::Pending);
});

test('it never purges downloads or conversions that are still active', function () {
    $movies = collect([
        purgeCandidate([
            'download_status' => DownloadStatus::Downloading,
            'conversion_status' => ConversionStatus::Converted,
        ]),
        purgeCandidate(['conversion_status' => ConversionStatus::Queued]),
        purgeCandidate(['conversion_status' => ConversionStatus::Converting]),
    ]);

    $movies->each(fn (Movie $movie) => storePurgeCandidateFiles($movie));

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    $movies->each(function (Movie $movie): void {
        Storage::disk('public')->assertExists("movies/{$movie->id}/movie.mp4");
        expect($movie->refresh()->download_status)->not->toBe(DownloadStatus::Pending);
    });
});

test('it resets the movie when its storage directory is already missing', function () {
    $movie = purgeCandidate();

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    expect($movie->refresh()->download_status)->toBe(DownloadStatus::Pending)
        ->and($movie->conversion_status)->toBe(ConversionStatus::Pending)
        ->and($movie->filename)->toBeNull();
});

test('it resumes a movie that is already being purged', function () {
    $movie = purgeCandidate([
        'download_status' => DownloadStatus::Purging,
        'last_watched_at' => now(),
        'updated_at' => now(),
    ]);

    storePurgeCandidateFiles($movie);

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    Storage::disk('public')->assertMissing("movies/{$movie->id}/movie.mp4");

    expect($movie->refresh()->download_status)->toBe(DownloadStatus::Pending)
        ->and($movie->conversion_status)->toBe(ConversionStatus::Pending);
});

test('it resets every temporary movie field after a successful purge', function () {
    $movie = purgeCandidate([
        'download_id' => (string) Str::uuid(),
        'download_file_index' => 7,
        'download_error' => 'Previous download error',
        'conversion_attempt' => (string) Str::uuid(),
        'conversion_error' => 'Previous conversion error',
        'conversion_started_at' => now()->subDays(40),
        'conversion_playable_at' => now()->subDays(40)->addMinute(),
        'conversion_completed_at' => now()->subDays(40)->addMinutes(2),
        'external_subtitles' => [
            [
                'file_index' => 3,
                'file_name' => 'movie.en.srt',
                'language' => 'eng',
                'output_file' => 'subtitles/external_00_eng.vtt',
            ],
        ],
        'external_subtitles_status' => ExternalSubtitleStatus::Ready,
    ]);

    storePurgeCandidateFiles($movie);

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    $movie->refresh();

    expect($movie->download_status)->toBe(DownloadStatus::Pending)
        ->and($movie->download_id)->toBeNull()
        ->and($movie->download_file_index)->toBeNull()
        ->and($movie->download_error)->toBeNull()
        ->and($movie->filename)->toBeNull()
        ->and($movie->conversion_status)->toBe(ConversionStatus::Pending)
        ->and($movie->conversion_attempt)->toBeNull()
        ->and($movie->conversion_error)->toBeNull()
        ->and($movie->conversion_started_at)->toBeNull()
        ->and($movie->conversion_playable_at)->toBeNull()
        ->and($movie->conversion_completed_at)->toBeNull()
        ->and($movie->external_subtitles)->toBeNull()
        ->and($movie->external_subtitles_status)->toBe(ExternalSubtitleStatus::None)
        ->and($movie->last_watched_at)->toBeNull();
});

test('it remains purging when deleting the storage directory fails', function () {
    $movie = purgeCandidate();
    $directory = "movies/{$movie->id}";
    $disk = Mockery::mock();

    $disk->shouldReceive('directoryExists')
        ->once()
        ->with($directory)
        ->andReturnTrue();
    $disk->shouldReceive('deleteDirectory')
        ->once()
        ->with($directory)
        ->andReturnFalse();

    Storage::shouldReceive('disk')
        ->once()
        ->with('public')
        ->andReturn($disk);

    $this->artisan('movies:purge-stale --isolated')->assertFailed();

    expect($movie->refresh()->download_status)->toBe(DownloadStatus::Purging);
});

test('it dispatches no job when preparing a movie that is being purged', function () {
    Queue::fake();

    $movie = purgeCandidate([
        'download_status' => DownloadStatus::Purging,
        'conversion_status' => ConversionStatus::Pending,
        'torrent_url' => 'https://example.com/movie.torrent',
    ]);

    app(MovieConversionController::class)->store($movie);

    Queue::assertNotPushed(DownloadMovie::class);
    Queue::assertNotPushed(ConvertMovie::class);
});

test('the page exposes a purging movie as unavailable without marking it watched', function () {
    $user = User::factory()->create();
    $movie = purgeCandidate([
        'download_status' => DownloadStatus::Purging,
        'conversion_status' => ConversionStatus::Converted,
        'last_watched_at' => null,
        'external_subtitles_status' => ExternalSubtitleStatus::Ready,
        'external_subtitles' => [
            [
                'file_index' => 3,
                'file_name' => 'movie.en.srt',
                'language' => 'eng',
                'output_file' => 'subtitles/external_00_eng.vtt',
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('movies.show', $movie))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('movies/show')
            ->where('moviePageData.download_status', DownloadStatus::Purging->value)
            ->where('moviePageData.playable', false)
            ->where('moviePageData.can_prepare', false)
            ->where('moviePageData.external_subtitles', [])
        );

    expect($movie->refresh()->last_watched_at)->toBeNull()
        ->and(WatchedMovie::query()
            ->where('movie_id', $movie->id)
            ->where('user_id', $user->id)
            ->doesntExist())->toBeTrue();
});
