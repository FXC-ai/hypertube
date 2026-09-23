<?php

use Illuminate\Support\Facades\Queue;
use App\Enums\ConversionStatus;

use App\Http\Controllers\MovieConversionController;
use App\Models\Movie;
use App\Jobs\ConvertMovie;


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
