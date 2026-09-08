<?php

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Illuminate\Support\Str;

it('creates movies with a path containing their database id', function () {
    $movie = Movie::factory()->create();

    expect($movie->filepath)
        ->toBe("/storage/app/public/movies/{$movie->id}/{$movie->filename}");
});

it('creates coherent conversion data for every status', function (ConversionStatus $status) {
    $movie = Movie::factory()->withConversionStatus($status)->create();

    expect($movie->conversion_status)->toBe($status);

    if (in_array($status, [ConversionStatus::Pending, ConversionStatus::Queued], true)) {
        expect($movie->conversion_attempt)->toBeNull()
            ->and($movie->conversion_error)->toBeNull()
            ->and($movie->conversion_started_at)->toBeNull()
            ->and($movie->conversion_playable_at)->toBeNull()
            ->and($movie->conversion_completed_at)->toBeNull();

        return;
    }

    expect(Str::isUuid($movie->conversion_attempt))->toBeTrue()
        ->and($movie->conversion_started_at)->not->toBeNull();

    if ($status === ConversionStatus::Failed) {
        expect($movie->conversion_error)->not->toBeNull()
            ->and($movie->conversion_completed_at)->toBeNull();

        if ($movie->conversion_playable_at !== null) {
            expect($movie->conversion_playable_at->greaterThan($movie->conversion_started_at))->toBeTrue();
        }

        return;
    }

    expect($movie->conversion_error)->toBeNull();

    if ($status === ConversionStatus::Converting) {
        expect($movie->conversion_playable_at)->toBeNull()
            ->and($movie->conversion_completed_at)->toBeNull();

        return;
    }

    expect($movie->conversion_playable_at->greaterThan($movie->conversion_started_at))->toBeTrue();

    if ($status === ConversionStatus::Playable) {
        expect($movie->conversion_completed_at)->toBeNull();

        return;
    }

    expect($movie->conversion_completed_at->greaterThan($movie->conversion_playable_at))->toBeTrue();
})->with(ConversionStatus::cases());
