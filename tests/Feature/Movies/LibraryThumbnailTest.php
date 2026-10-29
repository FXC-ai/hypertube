<?php

use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\MovieData;
use App\Models\Source;
use App\Models\User;
use App\Models\WatchedMovie;
use Illuminate\Support\Facades\Http;

/*
 * Subject III.2.2: each thumbnail shows the name of the video, its production year, its IMDb
 * grade and a cover image, and tells watched videos from unwatched ones.
 */
test('a thumbnail carries the name, the year, the grade, the cover and the watched state', function () {
    Http::fake();
    $user = User::factory()->create();

    Source::factory()->forSource(MovieSource::Wikidata)->create([
        'imdb_id' => 'tt0032599',
        'title' => 'His Girl Friday',
        'release_year' => 1940,
    ]);
    MovieData::factory()->create([
        'imdb_id' => 'tt0032599',
        'imdbRating' => 7.8,
        'poster' => 'https://poster.test/his-girl-friday.jpg',
    ]);
    WatchedMovie::markWatched($user, Movie::factory()->create(['imdb_id' => 'tt0032599']));

    Source::factory()->forSource(MovieSource::Wikidata)->create([
        'imdb_id' => 'tt0017136',
        'title' => 'Metropolis',
        'release_year' => 1927,
    ]);
    MovieData::factory()->create(['imdb_id' => 'tt0017136']);

    $this->actingAs($user)
        ->get(route('movies.index', ['sort' => 'name', 'dir' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('movies.data.0.title', 'His Girl Friday')
            ->where('movies.data.0.release_year', 1940)
            ->where('movies.data.0.data.imdbRating', 7.8)
            ->where('movies.data.0.data.poster', 'https://poster.test/his-girl-friday.jpg')
            ->where('movies.data.0.watched', true)
            ->where('movies.data.1.title', 'Metropolis')
            ->where('movies.data.1.watched', false));
});
