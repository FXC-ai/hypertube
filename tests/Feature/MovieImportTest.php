<?php

use App\Enums\DownloadStatus;
use App\Enums\MovieSource;
use App\Jobs\DownloadMovie;
use App\Models\Movie;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

const MAGNET = 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=Test.Video&tr=udp%3A%2F%2Ftracker.example.test%3A6969';

test('a guest cannot import a torrent link', function () {
    $this->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertRedirect(route('login'));
});

test('an authenticated user can add a video from a magnet link', function () {
    Queue::fake();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertRedirect();

    $movie = Movie::query()->firstOrFail();

    $response->assertRedirect(route('movies.show', $movie));

    expect($movie->torrent_url)->toBe(MAGNET)
        ->and($movie->download_status)->toBe(DownloadStatus::Pending)
        ->and(Source::query()->where('imdb_id', $movie->imdb_id)->firstOrFail()->title)
        ->toBe('Test.Video');

    Queue::assertPushed(DownloadMovie::class, fn (DownloadMovie $job): bool => $job->movieId === $movie->id);
});

test('importing the same magnet link again reuses the same movie', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertRedirect();

    $movie = Movie::query()->firstOrFail();

    $this->actingAs(User::factory()->create())
        ->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertRedirect(route('movies.show', $movie));

    expect(Movie::count())->toBe(1)
        ->and(Source::where('source', MovieSource::Whitelist->value)->count())->toBe(1);
});

test('a non torrent URL is refused with validation', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('movies.import'), ['torrent_url' => 'https://example.test/not-a-torrent'])
        ->assertSessionHasErrors('torrent_url');

    expect(Movie::count())->toBe(0);
});
