<?php

use Illuminate\Support\Facades\Queue;
use App\Models\Movie;
use App\Enums\ConversionStatus;
use App\Exceptions\MediaConversionException;
use App\Http\Controllers\MovieConversionController;
use App\Jobs\ConvertMovie;

use App\Services\Media\MediaProbe;
use App\Services\Media\TrackSelector;
use App\Services\Media\HlsCommandBuilder;
use App\Services\Media\HlsReadinessChecker;
use App\Services\Media\HlsMasterPlaylistBuilder;
use App\Services\Media\HlsConverter;
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

    expect(fn() => (new ConvertMovie($movie->id))->handle(
        new MediaProbe,
        new TrackSelector,
        new HlsCommandBuilder,
        new HlsReadinessChecker,
        new HlsMasterPlaylistBuilder,
        new HlsConverter,
    ))->toThrow(MediaConversionException::class, 'Source file not found.');

    expect($movie->refresh()->conversion_status)->toBe(ConversionStatus::Failed);
});
