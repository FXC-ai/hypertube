<?php

use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\MovieData;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function ytsMovieDetail(array $torrents): array
{
    return [
        'data' => [
            'movie' => [
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
                'torrents' => $torrents,
            ],
        ],
    ];
}

const SEEDED_TORRENTS = [
    ['url' => 'https://yts.gg/torrent/download/156FA', 'hash' => '156FA', 'quality' => '720p', 'seeds' => 3, 'peers' => 1, 'size_bytes' => 868420157],
    ['url' => 'https://yts.gg/torrent/download/15B78', 'hash' => '15B78', 'quality' => '1080p', 'seeds' => 12, 'peers' => 4, 'size_bytes' => 1610612736],
];

function ytsUser(): User
{
    // YTS is off by default server side, tests that need it must open the kill-switch.
    config(['services.sources.yts.enabled' => true]);

    return User::factory()->create(['sources' => ['wikidata' => true, 'yts' => true]]);
}

describe('selecting a YTS movie', function () {
    it('creates a local movie row from the YTS result and redirects to its page', function () {
        Http::fake([
            'api.yts.gg/api/v1/movies/tt0087643' => Http::response(ytsMovieDetail(SEEDED_TORRENTS)),
        ]);

        $this->actingAs(ytsUser());

        $response = $this->post(route('movies.yts.select'), ['imdb_code' => 'tt0087643']);

        $movie = Movie::where('imdb_id', 'tt0087643')->where('source', MovieSource::Yts->value)->first();

        expect($movie)->not->toBeNull();
        expect($movie->title)->toBe('Love Scenes');
        expect($movie->source_reference)->toBe('yts:79216');
        expect($movie->torrent_url)->toBe('https://yts.gg/torrent/download/15B78');
        expect($movie->seeds)->toBe(12);
        expect($movie->torrent_quality)->toBe('1080p');

        $response->assertRedirect(route('movies.show', $movie));
    });

    it('stores the YTS metadata in movie_data', function () {
        Http::fake([
            'api.yts.gg/api/v1/movies/tt0087643' => Http::response(ytsMovieDetail(SEEDED_TORRENTS)),
        ]);

        $this->actingAs(ytsUser());

        $this->post(route('movies.yts.select'), ['imdb_code' => 'tt0087643']);

        $data = MovieData::findByImdbIdAndSource('tt0087643', MovieSource::Yts);

        expect($data->plot)->toBe('An acclaimed actress makes an erotically-charged movie.');
        expect($data->genres)->toBe(['Drama', 'Romance']);
        expect($data->runtime)->toBe(90);
        expect($data->rated)->toBe('R');
        expect($data->release_date->format('Y-m-d'))->toBe('1984-01-01');
        expect($data->imdbRating)->toBe(4.6);
    });

    it('does not create a movie when no torrent has seeds', function () {
        Http::fake([
            'api.yts.gg/api/v1/movies/tt0087643' => Http::response(ytsMovieDetail([
                ['url' => 'https://yts.gg/torrent/download/156FA', 'hash' => '156FA', 'quality' => '720p', 'seeds' => 0, 'peers' => 0, 'size_bytes' => 868420157],
            ])),
        ]);

        $this->actingAs(ytsUser());

        $response = $this->post(route('movies.yts.select'), ['imdb_code' => 'tt0087643']);

        $response->assertRedirect(route('movies.index'));
        expect(Movie::count())->toBe(0);
    });

    it('refuses the selection when the user has not activated YTS', function () {
        Http::fake();

        $this->actingAs(User::factory()->create(['sources' => ['wikidata' => true, 'yts' => false]]));

        $response = $this->post(route('movies.yts.select'), ['imdb_code' => 'tt0087643']);

        $response->assertForbidden();
        expect(Movie::count())->toBe(0);
    });

    it('refuses an imdb code that is not a valid imdb id', function () {
        $this->actingAs(ytsUser());

        $response = $this->post(route('movies.yts.select'), ['imdb_code' => 'https://yts.gg/evil']);

        $response->assertSessionHasErrors('imdb_code');
    });
});
