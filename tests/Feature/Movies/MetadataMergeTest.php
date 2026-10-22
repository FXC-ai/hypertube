<?php

use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\MovieData;
use Illuminate\Support\Facades\Http;

describe('metadata merge', function () {
    it('takes the cast from the canonical OMDb row and the poster from the source row', function () {
        Http::fake();

        $movie = Movie::factory()->yts()->create(['imdb_id' => 'tt0087643']);

        MovieData::factory()->create([
            'imdb_id' => 'tt0087643',
            'source' => MovieSource::Omdb->value,
            'director' => 'Jacques Scandelari',
            'writers' => ['Antonio Rausch'],
            'actors' => ['Miria Peres'],
            'imdbRating' => 5.2,
            'poster' => 'https://omdb.example/poster.jpg',
        ]);

        MovieData::factory()->yts()->create([
            'imdb_id' => 'tt0087643',
            'poster' => 'https://yts.gg/cover.jpg',
            'imdbRating' => 4.6,
            'plot' => 'YTS summary',
        ]);

        $data = $movie->getEnrichedData();

        expect($data['director'])->toBe('Jacques Scandelari');
        expect($data['actors'])->toBe('Miria Peres');
        expect($data['writer'])->toBe('Antonio Rausch');
        expect($data['poster'])->toBe('https://yts.gg/cover.jpg');
        expect($data['plot'])->toBe('YTS summary');
        expect($data['imdbRating'])->toBe(4.6);
        expect($data['source'])->toBe('yts');
        expect($data['ratingLabel'])->toBe('YTS rating');

        Http::fake()->assertNothingSent();
    });

    it('fetches OMDb metadata when the canonical row is missing', function () {
        config(['services.omdb.key' => 'test-key']);

        Http::fake([
            'www.omdbapi.com/*' => Http::response([
                'Response' => 'True',
                'imdbID' => 'tt0087643',
                'Director' => 'Jacques Scandelari',
                'Actors' => 'Miria Peres, Sam Logol',
                'Writer' => 'Antonio Rausch',
                'Genre' => 'Drama, Romance',
                'Runtime' => '90 min',
                'Rated' => 'R',
                'Released' => '12 Jan 1984',
                'Language' => 'English',
                'Plot' => 'An acclaimed actress...',
                'Poster' => 'https://omdb.example/poster.jpg',
                'imdbRating' => '5.2',
                'imdbVotes' => '1,024',
                'BoxOffice' => 'N/A',
                'Awards' => '3 nominations',
            ]),
        ]);

        $movie = Movie::factory()->yts()->create(['imdb_id' => 'tt0087643']);

        $data = $movie->getEnrichedData();

        expect($data['director'])->toBe('Jacques Scandelari');
        expect($data['imdbRating'])->toBe(5.2);
        expect($data['year'])->toBe('1984');

        expect(MovieData::findByImdbIdAndSource('tt0087643', MovieSource::Omdb))->not->toBeNull();
    });

    it('links the canonical row to the movie that triggered the enrichment', function () {
        config(['services.omdb.key' => 'test-key']);

        Http::fake([
            'www.omdbapi.com/*' => Http::response([
                'Response' => 'True',
                'imdbID' => 'tt0087643',
                'Director' => 'Jacques Scandelari',
                'imdbRating' => 'N/A',
                'imdbVotes' => 'N/A',
                'BoxOffice' => 'N/A',
                'Released' => 'N/A',
            ]),
        ]);

        $movie = Movie::factory()->yts()->create(['imdb_id' => 'tt0087643']);

        $movie->getEnrichedData();

        expect(MovieData::findByImdbIdAndSource('tt0087643', MovieSource::Omdb)->movie_id)->toBe($movie->id);
    });

    it('keeps the movie link when the source row is stored again', function () {
        $movie = Movie::factory()->yts()->create(['imdb_id' => 'tt0087643']);

        MovieData::storeFromSource(MovieSource::Yts, 'tt0087643', [
            'movie_id' => $movie->id,
            'poster' => 'https://yts.gg/cover.jpg',
        ]);

        MovieData::storeFromSource(MovieSource::Yts, 'tt0087643', [
            'poster' => 'https://yts.gg/cover2.jpg',
        ]);

        $row = MovieData::findByImdbIdAndSource('tt0087643', MovieSource::Yts);

        expect($row->movie_id)->toBe($movie->id);
        expect($row->poster)->toBe('https://yts.gg/cover2.jpg');
    });
});
