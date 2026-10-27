<?php

use App\Models\MovieData;
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
