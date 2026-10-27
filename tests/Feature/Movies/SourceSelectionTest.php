<?php

use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'www.omdbapi.com/*' => Http::response(['Response' => 'False', 'Error' => 'Movie not found']),
    ]);

    $this->actingAs(User::factory()->create());
});

describe('loading a movie from a source', function () {
    it('creates the local movie from the catalog entry', function () {
        $entry = Source::factory()->forSource(MovieSource::Whitelist)->create(['title' => 'Cops']);

        $response = $this->get(route('movies.select', ['source' => 'whitelist', 'imdb_id' => $entry->imdb_id]));

        $movie = Movie::firstWhere('imdb_id', $entry->imdb_id);

        expect($movie)->not->toBeNull();
        expect($movie->torrent_url)->toBe($entry->torrent_url);
        $response->assertRedirect(route('movies.show', $movie));
    });

    it('keeps the torrent and the files of a movie already loaded', function () {
        $movie = Movie::factory()->create([
            'torrent_url' => 'https://archive.org/download/own/own.torrent',
            'filename' => 'already-here.mkv',
        ]);

        Source::factory()->forSource(MovieSource::Whitelist)->create([
            'imdb_id' => $movie->imdb_id,
            'torrent_url' => 'https://archive.org/download/other/other.torrent',
        ]);

        $response = $this->get(route('movies.select', ['source' => 'whitelist', 'imdb_id' => $movie->imdb_id]));

        $response->assertRedirect(route('movies.show', $movie));
        expect(Movie::count())->toBe(1);
        expect($movie->fresh()->torrent_url)->toBe('https://archive.org/download/own/own.torrent');
        expect($movie->fresh()->filename)->toBe('already-here.mkv');
    });

    it('flashes an error when the source does not offer that movie', function () {
        $entry = Source::factory()->forSource(MovieSource::Wikidata)->create();

        $response = $this->get(route('movies.select', ['source' => 'whitelist', 'imdb_id' => $entry->imdb_id]));

        $response->assertRedirect(route('movies.index'));
        $response->assertInertiaFlash('toast.type', 'error');
        expect(Movie::count())->toBe(0);
    });

    it('refuses a source that is not a catalog', function () {
        $this->get('/movies/sources/omdb/imdb/tt0000001')->assertNotFound();
    });

    it('shows the title and the source of the catalog entry', function () {
        $entry = Source::factory()->forSource(MovieSource::Whitelist)->create(['title' => 'Cops']);

        $movie = Movie::fromCatalogEntry($entry);

        $this->get(route('movies.show', $movie))
            ->assertOk()
            ->assertInertia(fn($assert) => $assert
                ->component('movies/show')
                ->where('moviePageData.title', 'Cops')
                ->where('moviePageData.source', 'whitelist')
            );
    });

    it('keeps the information of the first source when a movie is listed twice', function () {
        $first = Source::factory()->forSource(MovieSource::Wikidata)->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis']);
        Source::factory()->forSource(MovieSource::Whitelist)
            ->create(['imdb_id' => 'tt0000001', 'title' => 'Metropolis (restored)', 'torrent_url' => 'https://archive.org/download/restored/restored.torrent']);

        $movie = Movie::fromCatalogEntry($first);

        expect($movie->title)->toBe('Metropolis');
        expect($movie->source)->toBe(MovieSource::Wikidata);
    });
});
