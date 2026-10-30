<?php

use App\Models\Source;
use App\Services\Sources\ArchivePopularity;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, int>  $counts
 * @return array<string, array<string, array<string, mixed>>>
 */
function archiveSearchResponse(array $counts): array
{
    return [
        'response' => [
            'docs' => collect($counts)
                ->map(fn (int $downloads, string $identifier): array => [
                    'identifier' => $identifier,
                    'downloads' => $downloads,
                ])
                ->values()
                ->all(),
        ],
    ];
}

/**
 * @return int the number of archive.org requests that were sent
 */
function archiveRequests(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $recorded): bool => str_starts_with($recorded[0]->url(), 'https://archive.org/advancedsearch.php'))
        ->count();
}

describe('archive.org popularity', function () {
    it('reads the download count of every item it is asked about', function () {
        Http::fake([
            'archive.org/*' => Http::response(archiveSearchResponse(['metropolis' => 120, 'nosferatu' => 40])),
        ]);

        expect(app(ArchivePopularity::class)->fetch(['metropolis', 'nosferatu', 'unknown-item']))
            ->toBe(['metropolis' => 120, 'nosferatu' => 40]);
    });

    it('asks for fifty identifiers at once', function () {
        Http::fake([
            'archive.org/*' => Http::response(archiveSearchResponse([])),
        ]);

        app(ArchivePopularity::class)->fetch(array_map(fn (int $index): string => "item-{$index}", range(1, 250)));

        expect(archiveRequests())->toBe(5);
    });

    it('keeps every query shorter than what archive.org accepts', function () {
        Http::fake([
            'archive.org/*' => Http::response(archiveSearchResponse([])),
        ]);

        $identifiers = array_map(fn (int $index): string => 'a'.str_repeat('x', 39).$index, range(1, 200));

        app(ArchivePopularity::class)->fetch($identifiers);

        $queries = collect(Http::recorded())->map(function (array $recorded): string {
            parse_str(parse_url($recorded[0]->url(), PHP_URL_QUERY) ?? '', $query);

            return (string) ($query['q'] ?? '');
        });

        $lengths = $queries->map(fn (string $query): int => strlen($query));

        expect($lengths)->not->toBeEmpty()
            ->and($lengths->max())->toBeLessThanOrEqual(1500);
    });

    it('answers nothing when archive.org reports its refusal inside a successful response', function () {
        Http::fake([
            'archive.org/*' => Http::response(['error' => '[UNSUPPORTED_VALUE] A requested parameter has an inappropriate value']),
        ]);

        expect(app(ArchivePopularity::class)->fetch(['metropolis']))->toBe([]);
    });

    it('never asks for something that is not an archive.org item key', function () {
        Http::fake([
            'archive.org/*' => Http::response(archiveSearchResponse(['ok-item' => 7])),
        ]);

        expect(app(ArchivePopularity::class)->fetch(['ok-item', 'bad item', 'evil(*)']))
            ->toBe(['ok-item' => 7]);
    });

    it('answers nothing instead of breaking the import when archive.org fails', function () {
        Http::fake([
            'archive.org/*' => Http::response('Unavailable', 503),
        ]);

        expect(app(ArchivePopularity::class)->fetch(['metropolis']))->toBe([]);
    });

    it('fills the popularity of every catalog entry offering the same item', function () {
        Http::fake([
            'archive.org/*' => Http::response(archiveSearchResponse(['metropolis' => 16362])),
        ]);

        Source::factory()->create(['internet_archive_id' => 'metropolis']);
        Source::factory()->create(['internet_archive_id' => 'metropolis']);

        $this->artisan('archive:fetch-popularity')->assertSuccessful();

        expect(Source::query()->orderBy('id')->pluck('popularity')->all())->toBe([16362, 16362]);
    });

    it('reads back the identifier of the rows that only carry a torrent link', function () {
        Http::fake([
            'archive.org/*' => Http::response(archiveSearchResponse(['nosferatu' => 30])),
        ]);

        $entry = Source::factory()->create([
            'internet_archive_id' => null,
            'torrent_url' => 'https://archive.org/download/nosferatu/nosferatu_archive.torrent',
        ]);

        $this->artisan('archive:fetch-popularity')->assertSuccessful();

        $entry->refresh();

        expect($entry->internet_archive_id)->toBe('nosferatu')
            ->and($entry->popularity)->toBe(30);
    });

    it('stops after the asked number of entries', function () {
        Http::fake([
            // archive.org only answers for the identifiers the request actually asked for.
            'archive.org/*' => fn ($request) => Http::response(archiveSearchResponse(
                collect(['first' => 1, 'second' => 2])
                    ->filter(fn (int $downloads, string $identifier): bool => str_contains($request->url(), $identifier))
                    ->all(),
            )),
        ]);

        Source::factory()->create(['internet_archive_id' => 'first']);
        Source::factory()->create(['internet_archive_id' => 'second']);

        $this->artisan('archive:fetch-popularity', ['--limit' => 1])->assertSuccessful();

        expect(Source::where('internet_archive_id', 'first')->value('popularity'))->toBe(1)
            ->and(Source::where('internet_archive_id', 'second')->value('popularity'))->toBeNull();
    });

    it('fetches the popularity of the movies it imports when asked to', function () {
        Http::fake([
            'query.wikidata.org/*' => Http::response([
                'results' => [
                    'bindings' => [[
                        'imdbID' => ['value' => 'tt0000001'],
                        'internetArchiveID' => ['value' => 'metropolis'],
                        'titlesJson' => ['value' => json_encode(['fr' => 'Metropolis', 'en' => 'Metropolis'])],
                        'torrentURL' => ['value' => 'https://archive.org/download/metropolis/metropolis_archive.torrent'],
                        'releaseYear' => ['value' => '1927'],
                        'genresCsv' => ['value' => 'drama film'],
                    ]],
                ],
            ]),
            'archive.org/*' => Http::response(archiveSearchResponse(['metropolis' => 99])),
        ]);

        $this->artisan('import:wikidata-movies', ['--limit' => 5, '--popularity' => 5])
            ->assertSuccessful();

        $entry = Source::firstForImdbId('tt0000001');

        expect($entry->popularity)->toBe(99)
            ->and($entry->internet_archive_id)->toBe('metropolis');
    });
});
