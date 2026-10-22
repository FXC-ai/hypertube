<?php

use App\Models\Movie;
use App\Models\User;
use App\Models\WatchedMovie;

describe('watched relation', function () {
    it('marks a movie as watched when the user opens it', function () {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user)->get(route('movies.show', $movie))->assertOk();

        expect($user->hasWatched($movie))->toBeTrue()
            ->and(WatchedMovie::where('movie_id', $movie->id)->where('user_id', $user->id)->count())->toBe(1)
            ->and(WatchedMovie::first()->watched_at)->not->toBeNull();
    });

    it('does not duplicate the relation when the movie is opened again', function () {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user);
        $this->get(route('movies.show', $movie));
        $first = WatchedMovie::first();

        $this->get(route('movies.show', $movie))->assertOk();

        $again = WatchedMovie::first();

        expect(WatchedMovie::count())->toBe(1)
            ->and($again->id)->toBe($first->id)
            ->and($again->watched_at->equalTo($first->watched_at))->toBeTrue();
    });

    it('keeps the watched state private to each user', function () {
        $watcher = User::factory()->create();
        $other = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($watcher)->get(route('movies.show', $movie));

        expect($other->hasWatched($movie))->toBeFalse();
    });

    it('exposes the watched flag of the authenticated user on the index', function () {
        $user = User::factory()->create();
        $watcher = User::factory()->create();
        $watched = Movie::factory()->create(['title' => 'Inception']);
        $unwatched = Movie::factory()->create(['title' => 'Inception of nothing']);

        WatchedMovie::factory()->create(['movie_id' => $watched->id, 'user_id' => $watcher->id]);

        $this->actingAs($user)
            ->get(route('movies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('movies/index')
                ->has('movies.data', 2)
                ->where('movies.data.0.watched', false)
                ->where('movies.data.1.watched', false)
                ->where('filters.watched', 'all')
            );

        $this->actingAs($watcher)
            ->get(route('movies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('movies.data.0.watched', true)
                ->where('movies.data.1.watched', false)
            );
    });
});

describe('watched filter', function () {
    it('returns only watched movies', function () {
        $user = User::factory()->create();
        $watched = Movie::factory()->create(['title' => 'Watched one']);
        Movie::factory()->create(['title' => 'Untouched one']);
        WatchedMovie::factory()->create(['movie_id' => $watched->id, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('movies.index', ['watched' => 'watched']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('movies.data', 1)
                ->where('movies.data.0.id', $watched->id)
                ->where('movies.data.0.watched', true)
                ->where('filters.watched', 'watched')
            );
    });

    it('returns only unwatched movies', function () {
        $user = User::factory()->create();
        $watched = Movie::factory()->create(['title' => 'Watched one']);
        $unwatched = Movie::factory()->create(['title' => 'Untouched one']);
        WatchedMovie::factory()->create(['movie_id' => $watched->id, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('movies.index', ['watched' => 'unwatched']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('movies.data', 1)
                ->where('movies.data.0.id', $unwatched->id)
                ->where('movies.data.0.watched', false)
                ->where('filters.watched', 'unwatched')
            );
    });

    it('returns every movie when no filter is given', function () {
        $user = User::factory()->create();
        $watched = Movie::factory()->create(['title' => 'Watched one']);
        Movie::factory()->create(['title' => 'Untouched one']);
        WatchedMovie::factory()->create(['movie_id' => $watched->id, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('movies.index', ['watched' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('movies.data', 2)
                ->where('filters.watched', 'all')
            );
    });

    it('combines with the title search', function () {
        $user = User::factory()->create();
        $watchedMatch = Movie::factory()->create(['title' => 'Inception']);
        Movie::factory()->create(['title' => 'Alien']);
        WatchedMovie::factory()->create(['movie_id' => $watchedMatch->id, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('movies.index', ['search' => 'Inception', 'watched' => 'watched']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('movies.data', 1)
                ->where('movies.data.0.id', $watchedMatch->id)
            );
    });

    it('rejects an unknown filter value', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('movies.index', ['watched' => 'maybe']))
            ->assertRedirect()
            ->assertSessionHasErrors('watched');
    });
});
