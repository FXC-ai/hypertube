<?php

use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\Source;
use App\Models\User;
use App\Models\WatchedMovie;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // OmdbService skips every call without a key: the one of the local .env must not be
    // what makes these tests pass (the CI has none).
    config(['services.omdb.key' => 'test-api-key']);

    Http::fake([
        'www.omdbapi.com/*' => Http::response([
            'Response' => 'True',
            'Title' => 'Ignored by the catalog',
            'Poster' => 'https://poster.test/poster.jpg',
            'imdbRating' => '8.1',
        ]),
    ]);
});

/**
 * @return list<string>
 */
function resultTitles($response): array
{
    $titles = collect($response->inertiaProps('movies.data'))->pluck('title')->all();

    sort($titles);

    return $titles;
}

describe('merged catalog search', function () {
    it('queries every source the user activated', function () {
        $user = User::factory()->create();

        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);
        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000002', 'title' => 'M']);
        Source::factory()->forSource(MovieSource::Whitelist)->create(['imdb_id' => 'tt0000003', 'title' => 'Cops']);

        $response = $this->actingAs($user)->get(route('movies.index', ['perPage' => 10]));

        $response->assertOk();
        expect(resultTitles($response))->toBe(['Cops', 'M', 'Metropolis']);

        $response->assertInertia(fn ($assert) => $assert
            ->component('movies/index')
            ->where('movies.meta.total', 3)
            ->where('filters.sources', ['wikidata', 'whitelist'])
        );
    });

    it('lists a movie offered by several sources only once, keeping the first source', function () {
        $user = User::factory()->create();

        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);
        Source::factory()->forSource(MovieSource::Whitelist)
            ->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis (restored)']);

        $response = $this->actingAs($user)->get(route('movies.index'));

        $response->assertInertia(fn ($assert) => $assert
            ->has('movies.data', 1)
            ->where('movies.data.0.source', 'wikidata')
            ->where('movies.data.0.title', 'Metropolis')
            ->where('movies.meta.total', 1)
        );
    });

    it('ignores the sources the user deactivated', function () {
        $user = User::factory()->create(['sources' => ['wikidata' => true, 'whitelist' => false]]);

        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);
        Source::factory()->forSource(MovieSource::Whitelist)->create(['imdb_id' => 'tt0000003', 'title' => 'Cops']);

        $response = $this->actingAs($user)->get(route('movies.index'));

        expect(resultTitles($response))->toBe(['Metropolis']);
        $response->assertInertia(fn ($assert) => $assert->where('filters.sources', ['wikidata']));
    });

    it('ignores the sources the server disabled', function () {
        config(['services.sources.whitelist.enabled' => false]);

        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);
        Source::factory()->forSource(MovieSource::Whitelist)->create(['imdb_id' => 'tt0000003', 'title' => 'Cops']);

        $response = $this->actingAs(User::factory()->create())->get(route('movies.index'));

        expect(resultTitles($response))->toBe(['Metropolis']);
    });

    it('searches the titles of every source', function () {
        $user = User::factory()->create();

        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);
        Source::factory()->forSource(MovieSource::Whitelist)->create(['imdb_id' => 'tt0000003', 'title' => 'Cops']);

        $response = $this->actingAs($user)->get(route('movies.index', ['search' => 'cop']));

        expect(resultTitles($response))->toBe(['Cops']);
        $response->assertInertia(fn ($assert) => $assert->where('filters.search', 'cop'));
    });

    it('links the results to the movies already loaded on this server', function () {
        $user = User::factory()->create();

        $movie = Movie::factory()->create(['imdb_id' => 'tt0000001']);
        WatchedMovie::factory()->create(['movie_id' => $movie->id, 'user_id' => $user->id]);
        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);
        Source::factory()->forSource(MovieSource::Whitelist)->create(['imdb_id' => 'tt0000003', 'title' => 'Cops']);

        $response = $this->actingAs($user)->get(route('movies.index'));
        $results = collect($response->inertiaProps('movies.data'))->keyBy('imdb_id');

        expect($results['tt0000001']['id'])->toBe($movie->id);
        expect($results['tt0000001']['watched'])->toBeTrue();
        expect($results['tt0000003']['id'])->toBeNull();
        expect($results['tt0000003']['watched'])->toBeFalse();
    });

    it('slices the merged window into pages', function () {
        $user = User::factory()->create();

        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);
        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000002', 'title' => 'M']);
        Source::factory()->forSource(MovieSource::Whitelist)->create(['imdb_id' => 'tt0000003', 'title' => 'Cops']);

        $ordered = ['sort' => 'created_at', 'dir' => 'asc'];

        $firstPage = $this->actingAs($user)->get(route('movies.index', [...$ordered, 'perPage' => 2, 'page' => 1]));

        $firstPage->assertInertia(fn ($assert) => $assert
            ->has('movies.data', 2)
            ->where('movies.meta.total', 3)
            ->where('movies.meta.current_page', 1)
        );

        expect(collect($firstPage->inertiaProps('movies.data'))->pluck('title')->all())
            ->toBe(['Metropolis', 'M']);

        $secondPage = $this->actingAs($user)->get(route('movies.index', [...$ordered, 'perPage' => 2, 'page' => 2]));

        $secondPage->assertInertia(fn ($assert) => $assert
            ->has('movies.data', 1)
            ->where('movies.data.0.title', 'Cops')
        );
    });

    it('refuses a page too deep to merge', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('movies.index', ['page' => 101]))
            ->assertSessionHasErrors('page');
    });

    it('enriches every result with the cached metadata', function () {
        $user = User::factory()->create();

        Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);

        $this->actingAs($user)->get(route('movies.index'))
            ->assertInertia(fn ($assert) => $assert
                ->where('movies.data.0.imdb_id', 'tt0000001')
                ->where('movies.data.0.data.poster', 'https://poster.test/poster.jpg')
                ->where('movies.data.0.data.imdbRating', 8.1)
            );

        expect(Source::count())->toBe(1);
        expect(collect(Http::recorded())
            ->filter(fn (array $recorded): bool => str_starts_with($recorded[0]->url(), 'https://www.omdbapi.com/'))
            ->count())->toBe(1);
    });
});
