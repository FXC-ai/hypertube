<?php

use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\Source;
use App\Services\Sources\WikidataSource;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, string>  $criteria  the year and the genres the listing filters on
 * @return array<string, array<string, string>>
 */
function sparqlBinding(string $imdbId, string $item, string $title, array $criteria = []): array
{
    return [
        'imdbID' => ['value' => $imdbId],
        'internetArchiveID' => ['value' => $item],
        'titlesJson' => ['value' => json_encode(['fr' => $title, 'en' => $title])],
        'torrentURL' => ['value' => "https://archive.org/download/{$item}/{$item}_archive.torrent"],
        'releaseYear' => ['value' => $criteria['year'] ?? '1927'],
        'genresCsv' => ['value' => $criteria['genres'] ?? 'drama film|horror film'],
    ];
}

function fakeWikidata(array $bindings): void
{
    Http::fake([
        'query.wikidata.org/*' => Http::response(['results' => ['bindings' => $bindings]]),
    ]);
}

describe('catalog import', function () {
    it('stores the fetched movies in the sources table', function () {
        fakeWikidata([
            sparqlBinding('tt0000001', 'metropolis', 'Metropolis'),
            sparqlBinding('tt0000002', 'm', 'M'),
        ]);

        $inserted = WikidataSource::factory(MovieSource::Wikidata)->import(10);

        expect($inserted)->toBe(2);
        expect(Source::where('source', 'wikidata')->count())->toBe(2);
        expect(Source::orderBy('id')->first()->title)->toBe('Metropolis');
        expect(Movie::count())->toBe(0);
    });

    it('lists the whitelisted movies under the whitelist source too', function () {
        fakeWikidata([
            sparqlBinding('tt0000001', 'metropolis', 'Metropolis'),
            sparqlBinding('tt0000002', 'm', 'M'),
        ]);

        WikidataSource::factory(MovieSource::Wikidata)->import(10, ['tt0000002']);

        expect(Source::where('source', 'whitelist')->pluck('imdb_id')->all())->toBe(['tt0000002']);
        expect(Source::where('source', 'whitelist')->pluck('title')->all())->toBe(['M']);
    });

    it('keeps the titles of every language and the torrent link', function () {
        fakeWikidata([sparqlBinding('tt0000001', 'metropolis', 'Metropolis')]);

        WikidataSource::factory(MovieSource::Wikidata)->import(10);

        $entry = Source::firstForImdbId('tt0000001');

        expect($entry->titles)->toBe(['fr' => 'Metropolis', 'en' => 'Metropolis']);
        expect($entry->torrent_url)->toBe('https://archive.org/download/metropolis/metropolis_archive.torrent');
        expect($entry->internet_archive_id)->toBe('metropolis');
    });

    it('stores the year and the genres the listing is filtered and sorted on', function () {
        fakeWikidata([
            sparqlBinding('tt0000001', 'metropolis', 'Metropolis'),
            sparqlBinding('tt0000002', 'm', 'M', ['year' => '', 'genres' => '']),
        ]);

        WikidataSource::factory(MovieSource::Wikidata)->import(10);

        $metropolis = Source::firstForImdbId('tt0000001');
        $unknown = Source::firstForImdbId('tt0000002');

        expect($metropolis->release_year)->toBe(1927);
        expect($metropolis->genres)->toBe('|drama film|horror film|');
        expect($unknown->release_year)->toBeNull();
        expect($unknown->genres)->toBeNull();
    });

    it('does not duplicate the movies already in the catalog', function () {
        fakeWikidata([sparqlBinding('tt0000001', 'metropolis', 'Metropolis')]);

        $source = WikidataSource::factory(MovieSource::Wikidata);
        $source->import(10);
        $second = $source->import(10);

        expect($second)->toBe(0);
        expect(Source::count())->toBe(1);
    });

    it('inserts nothing during a dry run but reports what it would do', function () {
        fakeWikidata([
            sparqlBinding('tt0000001', 'metropolis', 'Metropolis'),
            sparqlBinding('tt0000002', 'm', 'M'),
        ]);

        $inserted = WikidataSource::factory(MovieSource::Wikidata)->import(10, ['tt0000001'], 500, true);

        expect($inserted)->toBe(3);
        expect(Source::count())->toBe(0);
    });

    it('fails when wikidata answers with an error', function () {
        Http::fake([
            'query.wikidata.org/*' => Http::response('Too Many Requests', 429),
        ]);

        expect(fn (): int => WikidataSource::factory(MovieSource::Wikidata)->import(10))
            ->toThrow(RuntimeException::class);
    });

    it('fills the IMDb grade the filters sort on when asked to enrich the movies', function () {
        config(['services.omdb.key' => 'test-api-key']);

        Http::fake([
            'query.wikidata.org/*' => Http::response([
                'results' => ['bindings' => [sparqlBinding('tt0000001', 'metropolis', 'Metropolis')]],
            ]),
            'www.omdbapi.com/*' => Http::response([
                'Response' => 'True',
                'imdbRating' => '8.3',
            ]),
        ]);

        $this->artisan('import:wikidata-movies', ['--limit' => 5, '--enrich' => 1])
            ->assertSuccessful();

        expect(Source::firstForImdbId('tt0000001')->imdb_rating)->toBe(8.3);
    });
});
