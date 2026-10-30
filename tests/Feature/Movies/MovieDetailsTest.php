<?php

use App\Models\Comment;
use App\Models\Movie;
use App\Models\MovieData;
use App\Models\User;

/*
 * Subject III.3: the video page presents the summary, the casting (producer, director, main
 * cast), the production year, the length, the IMDb grade, a cover image, and the comments.
 */
test('the video page shows everything collected about the movie', function () {
    $movie = Movie::factory()->create(['imdb_id' => 'tt0032599']);
    MovieData::factory()->create([
        'imdb_id' => 'tt0032599',
        'plot' => 'A newspaper editor uses every trick to keep his ex-wife on the paper.',
        'director' => 'Howard Hawks',
        'producer' => 'Lois C. Webber',
        'writers' => ['Charles Lederer'],
        'actors' => ['Cary Grant', 'Rosalind Russell'],
        'runtime' => 92,
        'release_date' => '1940-01-18',
        'imdbRating' => 7.8,
        'poster' => 'https://poster.test/his-girl-friday.jpg',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('movies.show', $movie))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('moviePageData.movieData.plot', 'A newspaper editor uses every trick to keep his ex-wife on the paper.')
            ->where('moviePageData.movieData.director', 'Howard Hawks')
            ->where('moviePageData.movieData.producer', 'Lois C. Webber')
            ->where('moviePageData.movieData.writer', 'Charles Lederer')
            ->where('moviePageData.movieData.actors', 'Cary Grant, Rosalind Russell')
            ->where('moviePageData.movieData.runtime', '92 min')
            ->where('moviePageData.movieData.year', '1940')
            ->where('moviePageData.movieData.imdbRating', 7.8)
            ->where('moviePageData.movieData.poster', 'https://poster.test/his-girl-friday.jpg'));
});

test('the video page lists the previous comments, newest first, with their author', function () {
    $movie = Movie::factory()->create();
    $author = User::factory()->create(['username' => 'critic']);
    Comment::factory()->for($movie)->for($author)->create(['content' => 'First', 'created_at' => now()->subDay()]);
    Comment::factory()->for($movie)->for($author)->create(['content' => 'Second']);
    Comment::factory()->create(['content' => 'On another movie']);

    $this->actingAs(User::factory()->create())
        ->get(route('movies.show', $movie))
        ->assertInertia(fn ($page) => $page
            ->has('comments.data', 2)
            ->where('comments.data.0.content', 'Second')
            ->where('comments.data.1.content', 'First')
            ->where('comments.data.0.user.username', 'critic'));
});

test('the video page tells the player the language the user prefers', function () {
    $movie = Movie::factory()->create();
    $user = User::factory()->create(['preferredlanguage' => 'french']);

    $this->actingAs($user)
        ->get(route('movies.show', $movie))
        ->assertInertia(fn ($page) => $page->where('moviePageData.preferredlanguage', 'french'));
});
