<?php

use App\Enums\MovieSource;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The catalog rows carry their own grade here, so OMDb answers with nothing.
    Http::fake([
        'www.omdbapi.com/*' => Http::response([
            'Response' => 'False',
            'Error' => 'Movie not found!',
        ]),
    ]);
});

/**
 * The titles of the listing, in the order the merged sources were ordered.
 *
 * @return list<string>
 */
function orderedTitles($response): array
{
    return collect($response->inertiaProps('movies.data'))->pluck('title')->all();
}

describe('criteria filters', function () {
    it('filters the catalog by genre', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Nosferatu', 'genres' => '|drama film|horror film|']);
        Source::factory()->create(['title' => 'Cops', 'genres' => '|parody film|']);

        $response = $this->actingAs($user)->get(route('movies.index', ['genre' => 'horror film']));

        expect(orderedTitles($response))->toBe(['Nosferatu']);
        $response->assertInertia(fn ($assert) => $assert
            ->where('filters.genre', 'horror film')
            ->where('movies.meta.total', 1)
        );
    });

    it('matches a genre whole, never inside a longer label', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Nosferatu', 'genres' => '|drama film|']);
        Source::factory()->create(['title' => 'Cops', 'genres' => '|comedy film|']);

        $response = $this->actingAs($user)->get(route('movies.index', ['genre' => 'film']));

        expect(orderedTitles($response))->toBe([]);
    });

    it('never lets a criterion wildcards escape the genre match', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Nosferatu', 'genres' => '|drama film|']);
        Source::factory()->create(['title' => 'Cops', 'genres' => '|comedy film|']);

        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['genre' => '%']))))->toBe([]);
    });

    it('filters the catalog by production year', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Metropolis', 'release_year' => 1927]);
        Source::factory()->create(['title' => 'M', 'release_year' => 1931]);
        Source::factory()->create(['title' => 'Cops', 'release_year' => 1970]);
        Source::factory()->create(['title' => 'Unknown date', 'release_year' => null]);

        $response = $this->actingAs($user)->get(route('movies.index', [
            'yearFrom' => 1920,
            'yearTo' => 1940,
            'sort' => 'created_at',
            'dir' => 'asc',
        ]));

        expect(orderedTitles($response))->toBe(['Metropolis', 'M']);
        $response->assertInertia(fn ($assert) => $assert
            ->where('filters.yearFrom', 1920)
            ->where('filters.yearTo', 1940)
        );
    });

    it('filters the catalog by IMDb grade', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Metropolis', 'imdb_rating' => 8.3]);
        Source::factory()->create(['title' => 'M', 'imdb_rating' => 6.9]);
        Source::factory()->create(['title' => 'Cops', 'imdb_rating' => 2.4]);
        Source::factory()->create(['title' => 'Unranked', 'imdb_rating' => null]);

        $response = $this->actingAs($user)->get(route('movies.index', [
            'ratingFrom' => 6.5,
            'sort' => 'created_at',
            'dir' => 'asc',
        ]));

        expect(orderedTitles($response))->toBe(['Metropolis', 'M']);
        $response->assertInertia(fn ($assert) => $assert->where('filters.ratingFrom', 6.5));

        $this->actingAs($user)
            ->get(route('movies.index', ['ratingTo' => 3]))
            ->assertInertia(fn ($assert) => $assert->has('movies.data', 1));
    });

    it('combines the criteria of a single listing', function () {
        $user = User::factory()->create();

        Source::factory()->create([
            'title' => 'Metropolis',
            'genres' => '|drama film|science fiction film|',
            'release_year' => 1927,
            'imdb_rating' => 8.3,
        ]);
        Source::factory()->create([
            'title' => 'Metropolis II',
            'genres' => '|drama film|science fiction film|',
            'release_year' => 1990,
            'imdb_rating' => 2.1,
        ]);
        Source::factory()->create([
            'title' => 'Metropolis III',
            'genres' => '|comedy film|',
            'release_year' => 1927,
            'imdb_rating' => 8.3,
        ]);

        $response = $this->actingAs($user)->get(route('movies.index', [
            'search' => 'metropolis',
            'genre' => 'science fiction film',
            'yearTo' => 1930,
            'ratingFrom' => 8,
        ]));

        expect(orderedTitles($response))->toBe(['Metropolis']);
    });

    it('offers only the genres the activated catalogs actually list', function () {
        config(['services.sources.whitelist.enabled' => false]);

        Source::factory()->forSource(MovieSource::Wikidata)->create(['genres' => '|western film|']);
        Source::factory()->forSource(MovieSource::Wikidata)->create(['genres' => '|drama film|western film|']);
        Source::factory()->forSource(MovieSource::Whitelist)->create(['genres' => '|musical film|']);
        Source::factory()->forSource(MovieSource::Wikidata)->create(['genres' => null]);

        $this->actingAs(User::factory()->create())->get(route('movies.index'))
            ->assertInertia(fn ($assert) => $assert
                ->where('filters.genres', ['drama film', 'western film'])
            );
    });

    it('keeps the criteria while scrolling to the next page', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'A', 'genres' => '|drama film|']);
        Source::factory()->create(['title' => 'B', 'genres' => '|drama film|']);
        Source::factory()->create(['title' => 'C', 'genres' => '|comedy film|']);

        $this->actingAs($user)->get(route('movies.index', [
            'genre' => 'drama film',
            'perPage' => 1,
            'page' => 2,
            'sort' => 'created_at',
            'dir' => 'asc',
        ]))
            ->assertInertia(fn ($assert) => $assert
                ->where('filters.genre', 'drama film')
                ->where('movies.meta.current_page', 2)
                ->where('movies.meta.total', 2)
                ->where('movies.data.0.title', 'B')
            );
    });
});

describe('sorting criteria', function () {
    it('offers every criterion the listing can be sorted on', function () {
        $this->actingAs(User::factory()->create())->get(route('movies.index'))
            ->assertInertia(fn ($assert) => $assert
                ->where('filters.sorts', [
                    'popularity' => 'Popularity (downloads)',
                    'created_at' => 'Date added',
                    'name' => 'Name',
                    'year' => 'Production year',
                    'rating' => 'IMDb rating',
                ])
                ->where('filters.sort', 'popularity')
                ->where('filters.dir', 'desc')
            );
    });

    it('shows the most popular films first when nothing was searched', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Metropolis', 'popularity' => 2000]);
        Source::factory()->create(['title' => 'M', 'popularity' => 60000]);
        Source::factory()->create(['title' => 'Unknown', 'popularity' => null]);

        expect(orderedTitles($this->actingAs($user)->get(route('movies.index'))))
            ->toBe(['M', 'Metropolis', 'Unknown']);
    });

    it('sorts the merged listing by name in both directions', function () {
        $user = User::factory()->create();

        Source::factory()->forSource(MovieSource::Wikidata)->create(['title' => 'Zombie']);
        Source::factory()->forSource(MovieSource::Whitelist)->create(['title' => 'Alien']);
        Source::factory()->forSource(MovieSource::Wikidata)->create(['title' => 'Beetle']);

        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['sort' => 'name']))))
            ->toBe(['Alien', 'Beetle', 'Zombie']);
        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['sort' => 'name', 'dir' => 'desc']))))
            ->toBe(['Zombie', 'Beetle', 'Alien']);
    });

    it('sorts a search by name as the subject asks', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Zombie movie']);
        Source::factory()->create(['title' => 'Alien movie']);
        Source::factory()->create(['title' => 'Beetle movie']);

        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['search' => 'movie']))))
            ->toBe(['Alien movie', 'Beetle movie', 'Zombie movie']);
    });

    it('sorts the merged listing by production year', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'M', 'release_year' => 1931]);
        Source::factory()->create(['title' => 'Metropolis', 'release_year' => 1927]);
        Source::factory()->create(['title' => 'Cops', 'release_year' => 1970]);

        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['sort' => 'year', 'dir' => 'desc']))))
            ->toBe(['Cops', 'M', 'Metropolis']);
    });

    it('always ranks an entry missing the criterion last', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Ranked', 'imdb_rating' => 7.5]);
        Source::factory()->create(['title' => 'Unranked', 'imdb_rating' => null]);

        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['sort' => 'rating']))))
            ->toBe(['Ranked', 'Unranked']);
        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['sort' => 'rating', 'dir' => 'desc']))))
            ->toBe(['Ranked', 'Unranked']);
    });
});

describe('merged pagination', function () {
    it('never shows the same film twice across the pages of the default listing', function () {
        $user = User::factory()->create();

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $title) {
            Source::factory()->create(['title' => $title, 'popularity' => null]);
        }

        $pages = [
            collect($this->actingAs($user)->get(route('movies.index', ['perPage' => 3, 'page' => 1]))
                ->inertiaProps('movies.data'))->pluck('title')->all(),
            collect($this->actingAs($user)->get(route('movies.index', ['perPage' => 3, 'page' => 2]))
                ->inertiaProps('movies.data'))->pluck('title')->all(),
        ];

        expect($pages[0])->toHaveCount(3)
            ->and($pages[1])->toHaveCount(3)
            ->and(array_intersect($pages[0], $pages[1]))->toBe([]);
    });

    it('tells the infinite scroll the page that comes next, without repeating the page parameter', function () {
        $user = User::factory()->create();

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $title) {
            Source::factory()->create(['title' => $title]);
        }

        $response = $this->actingAs($user)
            ->get(route('movies.index', ['perPage' => 3, 'page' => 1]));

        expect(data_get($response->viewData('page'), 'scrollProps.movies.nextPage'))->toBe(2);

        $nextUrl = (string) collect($response->inertiaProps('movies.meta.links'))->last()['url'];

        expect($nextUrl)->toContain('page=2')
            ->and(substr_count($nextUrl, 'page='))->toBe(1);
    });

    it('pages the popular listing in the very order it is sorted on', function () {
        $user = User::factory()->create();

        Source::factory()->create(['title' => 'Low', 'popularity' => 10]);
        Source::factory()->create(['title' => 'Middle', 'popularity' => 20]);
        Source::factory()->create(['title' => 'High', 'popularity' => 30]);
        Source::factory()->create(['title' => 'Unknown', 'popularity' => null]);

        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['perPage' => 2, 'page' => 1]))))
            ->toBe(['High', 'Middle']);
        expect(orderedTitles($this->actingAs($user)->get(route('movies.index', ['perPage' => 2, 'page' => 2]))))
            ->toBe(['Low', 'Unknown']);
    });

    it('pages a descending sort of films sharing the same criterion', function () {
        $user = User::factory()->create();

        foreach (['A', 'B', 'C', 'D'] as $title) {
            Source::factory()->create(['title' => $title, 'release_year' => 1930, 'popularity' => null]);
        }

        $pages = [
            collect($this->actingAs($user)->get(route('movies.index', ['sort' => 'year', 'dir' => 'desc', 'perPage' => 2, 'page' => 1]))
                ->inertiaProps('movies.data'))->pluck('title')->all(),
            collect($this->actingAs($user)->get(route('movies.index', ['sort' => 'year', 'dir' => 'desc', 'perPage' => 2, 'page' => 2]))
                ->inertiaProps('movies.data'))->pluck('title')->all(),
        ];

        expect($pages[0])->toHaveCount(2)
            ->and($pages[1])->toHaveCount(2)
            ->and(array_intersect($pages[0], $pages[1]))->toBe([]);
    });

    it('stops the infinite scroll once every source ran out of distinct films', function () {
        $user = User::factory()->create();

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $title) {
            Source::factory()->forSource(MovieSource::Wikidata)->create(['title' => $title]);
        }

        // The whitelist offers two of them again, so the source totals alone would
        // promise more films than the merged listing can ever hand over.
        foreach (['E', 'F'] as $title) {
            Source::factory()
                ->forSource(MovieSource::Whitelist)
                ->duplicateOf(Source::query()->where('title', $title)->first())
                ->create();
        }

        $response = $this->actingAs($user)->get(route('movies.index', ['perPage' => 1, 'page' => 7]));

        $response->assertInertia(fn ($assert) => $assert
            ->has('movies.data', 0)
            ->where('movies.meta.total', 6)
        );

        // The scroll metadata is a page level key, next to the props rather than inside them.
        expect(data_get($response->viewData('page'), 'scrollProps.movies.nextPage'))->toBeNull();
    });
});

describe('criteria validation', function () {
    it('refuses a criterion the listing cannot be sorted on', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('movies.index', ['sort' => 'imdb_rating']))
            ->assertSessionHasErrors('sort');
    });

    it('refuses a grade outside the IMDb scale', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('movies.index', ['ratingFrom' => 12]))
            ->assertSessionHasErrors('ratingFrom');
    });

    it('refuses a production year from before cinema exists', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('movies.index', ['yearFrom' => 1200]))
            ->assertSessionHasErrors('yearFrom');
    });

    it('refuses a genre longer than a label', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('movies.index', ['genre' => str_repeat('drama', 20)]))
            ->assertSessionHasErrors('genre');
    });
});
