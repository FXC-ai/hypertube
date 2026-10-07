<?php

use App\Enums\ConversionStatus;
use App\Http\Controllers\MovieConversionController;
use App\Jobs\ConvertMovie;
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
