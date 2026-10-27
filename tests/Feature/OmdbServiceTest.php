<?php

use App\Enums\MovieSource;
use App\Models\MovieData;
use App\Models\Source;
use App\Services\OmdbService;
use Illuminate\Support\Facades\Http;

test('missing optional OMDb fields do not prevent movie data from being stored', function (
    string $missingField,
    string $databaseColumn,
    string $normalizedField,
) {
    $imdbId = 'tt23134844';
    $response = [
        'Response' => 'True',
        'Poster' => 'https://example.com/poster.jpg',
        'Plot' => 'A test movie.',
        'Genre' => 'Drama, Mystery',
        'Runtime' => '90 min',
        'Actors' => 'Jane Doe, John Doe',
        'Director' => 'Jane Director',
        'Writer' => 'John Writer',
        'Rated' => 'PG',
        'Language' => 'English',
        'Awards' => 'None',
        'imdbRating' => '7.5',
        'imdbVotes' => '1234',
        'BoxOffice' => '123456',
        'Released' => '01 Jan 2020',
    ];

    unset($response[$missingField]);

    Http::fake([
        'www.omdbapi.com/*' => Http::response($response),
    ]);

    $movieData = (new OmdbService('test-api-key'))->enrichByImdbId($imdbId);

    expect($movieData)
        ->toBeInstanceOf(MovieData::class)
        ->and($movieData->displayArray()[$normalizedField])->toBeNull();

    $this->assertDatabaseHas('movie_data', [
        'imdb_id' => $imdbId,
        $databaseColumn => null,
    ]);
})->with([
    'missing BoxOffice' => ['BoxOffice', 'boxOffice', 'boxOffice'],
    'missing IMDb rating' => ['imdbRating', 'imdbRating', 'imdbRating'],
    'missing IMDb votes' => ['imdbVotes', 'imdbVotes', 'imdbVotes'],
    'missing release date' => ['Released', 'release_date', 'released'],
]);

test('the catalog copy of the grade and of the production year follows the metadata', function () {
    $imdbId = 'tt0000001';

    Source::factory()->create(['imdb_id' => $imdbId, 'release_year' => null]);
    Source::factory()->forSource(MovieSource::Whitelist)->create(['imdb_id' => $imdbId, 'release_year' => 1927]);

    Http::fake([
        'www.omdbapi.com/*' => Http::response([
            'Response' => 'True',
            'imdbRating' => '8.3',
            'Released' => '01 Jan 2020',
        ]),
    ]);

    (new OmdbService('test-api-key'))->enrichByImdbId($imdbId);

    $entries = Source::query()->orderBy('id')->get();

    expect($entries->pluck('imdb_rating')->all())->toBe([8.3, 8.3])
        ->and($entries->pluck('release_year')->all())->toBe([2020, 1927]);
});
