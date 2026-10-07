<?php

use App\Enums\ConversionStatus;
use App\Exceptions\MediaConversionException;
use App\Http\Controllers\MovieConversionController;
use App\Jobs\ConvertMovie;
use App\Models\Movie;
use App\Services\Media\HlsCommandBuilder;
use App\Services\Media\HlsConverter;
use App\Services\Media\HlsMasterPlaylistBuilder;
use App\Services\Media\HlsReadinessChecker;
use App\Services\Media\MediaProbe;
use App\Services\Media\MovieInputResolver;
use App\Services\Media\TrackSelector;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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

test('a queued movie is claimed atomically and conversion reaches converted', function () {
    Storage::fake('public');

    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Queued)
        ->create(['filename' => 'test2.mkv']);

    Storage::disk('public')->put(
        "movies/{$movie->id}/{$movie->filename}",
        file_get_contents(base_path('tests/Fixtures/test2.mkv')),
    );

    (new ConvertMovie($movie->id))->handle(
        new MediaProbe,
        new TrackSelector,
        new HlsCommandBuilder,
        new HlsReadinessChecker,
        new HlsMasterPlaylistBuilder,
        new HlsConverter,
        app(MovieInputResolver::class),
    );

    $movie->refresh();

    expect($movie->conversion_status)->toBe(ConversionStatus::Converted)
        ->and($movie->conversion_attempt)->not->toBeNull()
        ->and($movie->conversion_started_at)->not->toBeNull()
        ->and($movie->conversion_playable_at)->not->toBeNull()
        ->and($movie->conversion_completed_at)->not->toBeNull();
});

test('a conversion failure marks the movie as failed and rethrows the exception', function () {
    Storage::fake('public');

    $movie = Movie::factory()->withConversionStatus(ConversionStatus::Queued)->create(['filename' => 'source.mkv']);

    expect(fn () => (new ConvertMovie($movie->id))->handle(
        new MediaProbe,
        new TrackSelector,
        new HlsCommandBuilder,
        new HlsReadinessChecker,
        new HlsMasterPlaylistBuilder,
        new HlsConverter,
        app(MovieInputResolver::class),
    ))->toThrow(MediaConversionException::class, 'Source file not found.');

    expect($movie->refresh()->conversion_status)->toBe(ConversionStatus::Failed);
});

test('a movie stored in a subfolder of its torrent is converted', function () {
    Storage::fake('public');

    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Queued)
        ->create(['filename' => 'M(1931)/test2.mkv']);

    Storage::disk('public')->put(
        "movies/{$movie->id}/{$movie->filename}",
        file_get_contents(base_path('tests/Fixtures/test2.mkv')),
    );

    (new ConvertMovie($movie->id))->handle(
        new MediaProbe,
        new TrackSelector,
        new HlsCommandBuilder,
        new HlsReadinessChecker,
        new HlsMasterPlaylistBuilder,
        new HlsConverter,
        app(MovieInputResolver::class),
    );

    expect($movie->refresh()->conversion_status)->toBe(ConversionStatus::Converted);
});

test('a file name that leaves the movie directory is rejected and the error is recorded', function (string $filename) {
    Storage::fake('public');

    $movie = Movie::factory()->withConversionStatus(ConversionStatus::Queued)->create(['filename' => $filename]);

    expect(fn () => (new ConvertMovie($movie->id))->handle(
        new MediaProbe,
        new TrackSelector,
        new HlsCommandBuilder,
        new HlsReadinessChecker,
        new HlsMasterPlaylistBuilder,
        new HlsConverter,
        app(MovieInputResolver::class),
    ))->toThrow(RuntimeException::class, 'Invalid file name.');

    $movie->refresh();

    expect($movie->conversion_status)->toBe(ConversionStatus::Failed)
        ->and($movie->conversion_error)->toBe('Invalid file name.');
})->with([
    'parent directory' => '../movie.mkv',
    'nested parent directory' => 'M(1931)/../../movie.mkv',
    'absolute path' => '/etc/passwd',
    'backslash' => 'M(1931)\\movie.mkv',
    'empty segment' => 'M(1931)//movie.mkv',
    'current directory' => './movie.mkv',
]);
