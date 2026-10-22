<?php

use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\MovieData;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ytsMovie(array $overrides = []): array
{
    return array_merge([
        'id' => 79216,
        'imdb_code' => 'tt0087643',
        'title' => 'Love Scenes',
        'year' => 1984,
        'rating' => 4.6,
        'runtime' => 90,
        'genres' => ['Drama', 'Romance'],
        'summary' => 'An acclaimed actress makes an erotically-charged movie.',
        'language' => 'en',
        'mpa_rating' => 'R',
        'large_cover_image' => 'https://yts.gg/assets/cover.jpg',
        'state' => 'ok',
        'torrents' => [
            ['url' => 'https://yts.gg/torrent/download/156FA', 'hash' => '156FA', 'quality' => '720p', 'seeds' => 0, 'peers' => 0, 'size_bytes' => 868420157],
            ['url' => 'https://yts.gg/torrent/download/15B78', 'hash' => '15B78', 'quality' => '1080p', 'seeds' => 0, 'peers' => 0, 'size_bytes' => 1610612736],
        ],
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $movies
 * @return array<string, mixed>
 */
function ytsSearchResponse(array $movies, int $pages = 1): array
{
    return [
        'data' => [
            'movies' => $movies,
            'info' => ['page' => 1, 'pages' => $pages, 'limit' => 5],
        ],
    ];
}

function seededYtsMovie(): array
{
    return ytsMovie([
        'torrents' => [
            ['url' => 'https://yts.gg/torrent/download/156FA', 'hash' => '156FA', 'quality' => '720p', 'seeds' => 3, 'peers' => 1, 'size_bytes' => 868420157],
            ['url' => 'https://yts.gg/torrent/download/15B78', 'hash' => '15B78', 'quality' => '1080p', 'seeds' => 12, 'peers' => 4, 'size_bytes' => 1610612736],
        ],
    ]);
}

function userWithSources(array $sources): User
{
    return User::factory()->create(['sources' => $sources]);
}

describe('movie listing with several sources', function () {
    it('queries only the wikidata catalog when YTS is not activated', function () {
        Http::fake();
        Movie::factory()->wikidata()->create(['title' => 'Wikidata Movie']);

        $user = userWithSources(['wikidata' => true, 'yts' => false]);
        $this->actingAs($user);

        $response = $this->get(route('movies.index'));

        $response->assertOk();
        $response->assertInertia(
            fn($assert) => $assert
                ->component('movies/index')
                ->has('movies.data', 1)
                ->where('movies.data.0.source', 'wikidata')
                ->where('filters.search', null)
                ->where('filters.sources', ['wikidata'])
        );

        Http::fake()->assertNothingSent();
    });

    it('appends the YTS results when the user activates YTS', function () {
        config(['services.sources.yts.enabled' => true]);

        Http::fake([
            'api.yts.gg/api/v1/movies*' => Http::response(ytsSearchResponse([seededYtsMovie()])),
        ]);

        Movie::factory()->wikidata()->create(['title' => 'Wikidata Movie']);

        $user = userWithSources(['wikidata' => true, 'yts' => true]);
        $this->actingAs($user);

        $response = $this->get(route('movies.index'));

        $response->assertOk();
        $response->assertInertia(
            fn($assert) => $assert
                ->component('movies/index')
                ->has('movies.data', 2)
                ->where('movies.data.0.source', 'wikidata')
                ->where('movies.data.1.source', 'yts')
                ->where('movies.data.1.title', 'Love Scenes')
                ->where('movies.data.1.id', null)
                ->where('movies.data.1.selectable', true)
                ->where('filters.sources', ['wikidata', 'yts'])
        );
    });

    it('combines both sources for a title search', function () {
        config(['services.sources.yts.enabled' => true]);

        Http::fake([
            'api.yts.gg/api/v1/movies*' => Http::response(ytsSearchResponse([ytsMovie(['title' => 'Batman Returns'])])),
        ]);

        Movie::factory()->wikidata()->create(['title' => 'Batman Begins']);
        Movie::factory()->wikidata()->create(['title' => 'Unrelated Film']);

        $user = userWithSources(['wikidata' => true, 'yts' => true]);
        $this->actingAs($user);

        $response = $this->get(route('movies.index', ['search' => 'Batman']));

        $response->assertOk();
        $response->assertInertia(
            fn($assert) => $assert
                ->has('movies.data', 2)
                ->where('movies.data.0.title', 'Batman Begins')
                ->where('movies.data.1.title', 'Batman Returns')
        );
    });

    it('marks a YTS movie without any seeded torrent as not selectable', function () {
        config(['services.sources.yts.enabled' => true]);

        Http::fake([
            'api.yts.gg/api/v1/movies*' => Http::response(ytsSearchResponse([ytsMovie()])),
        ]);

        $user = userWithSources(['wikidata' => true, 'yts' => true]);
        $this->actingAs($user);

        $response = $this->get(route('movies.index'));

        $response->assertInertia(
            fn($assert) => $assert
                ->has('movies.data', 1)
                ->where('movies.data.0.selectable', false)
        );
    });

    it('does not query a source that is globally disabled even when the user activated it', function () {
        Http::fake();
        config(['services.sources.yts.enabled' => false]);

        $user = userWithSources(['wikidata' => true, 'yts' => true]);
        $this->actingAs($user);

        $response = $this->get(route('movies.index'));

        $response->assertOk();
        $response->assertInertia(fn($assert) => $assert->where('filters.sources', ['wikidata']));

        Http::fake()->assertNothingSent();
    });

    it('fills the YTS listing metadata from the cached OMDb row without calling the API', function () {
        config(['services.sources.yts.enabled' => true]);
        config(['services.omdb.key' => 'test-key']);

        Http::fake([
            'api.yts.gg/api/v1/movies*' => Http::response(ytsSearchResponse([seededYtsMovie()])),
            'www.omdbapi.com/*' => Http::response([
                'Response' => 'True',
                'imdbID' => 'tt0087643',
                'Director' => 'From OMDb API',
            ]),
        ]);

        MovieData::factory()->create([
            'imdb_id' => 'tt0087643',
            'source' => MovieSource::Omdb->value,
            'director' => 'Jacques Scandelari',
            'actors' => ['Miria Peres'],
            'imdbRating' => 5.2,
        ]);

        $this->actingAs(userWithSources(['wikidata' => true, 'yts' => true]));

        $response = $this->get(route('movies.index'));

        $response->assertInertia(
            fn($assert) => $assert
                ->where('movies.data.0.data.director', 'Jacques Scandelari')
                ->where('movies.data.0.data.actors', 'Miria Peres')
                ->where('movies.data.0.data.imdbRating', 4.6)
                ->where('movies.data.0.data.ratingLabel', 'YTS rating')
        );
    });
});
