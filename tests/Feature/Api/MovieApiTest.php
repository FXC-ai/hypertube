<?php

use App\Models\Movie;
use App\Models\MovieData;
use App\Models\Source;
use App\Models\User;
use Laravel\Passport\Passport;

function frontPageMovie(?int $popularity = null): Movie
{
    $movie = Movie::factory()->withCatalogEntry(title: 'A public domain film')->create();

    Source::query()->where('imdb_id', $movie->imdb_id)->update(['popularity' => $popularity]);

    return $movie;
}

test('the front page is readable without a token', function () {
    frontPageMovie();

    $this->getJson('/api/movies')->assertOk()->assertJsonStructure([
        'data' => [['id', 'name']],
        'meta' => ['total'],
    ]);
});

test('the front page starts with the most popular movies', function () {
    $quiet = frontPageMovie(popularity: 5);
    $popular = frontPageMovie(popularity: 500);
    $unknown = Movie::factory()->create();

    $ids = collect($this->getJson('/api/movies')->json('data'))
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$popular->id, $quiet->id, $unknown->id]);
});

test('a movie asks for an access token', function () {
    $movie = frontPageMovie();

    $this->getJson('/api/movies/'.$movie->id)->assertUnauthorized();
});

test('a movie answers with everything the server collected about it', function () {
    $movie = Movie::factory()->withCatalogEntry(title: 'The famous film')->create();
    MovieData::factory()->forImdbId($movie->imdb_id)->create([
        'runtime' => 95,
        'imdbRating' => 7.5,
        'release_date' => '1995-05-05',
        'plot' => 'A story worth telling.',
        'director' => 'Jane Doe',
    ]);
    $movie->comments()->create(['user_id' => User::factory()->create()->id, 'content' => 'A comment']);

    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/movies/'.$movie->id)->assertOk()->assertJson([
        'data' => [
            'id' => $movie->id,
            'name' => 'The famous film',
            'imdb_rating' => 7.5,
            'production_year' => '1995',
            'length' => 95,
            'summary' => 'A story worth telling.',
            'director' => 'Jane Doe',
            'comments_count' => 1,
        ],
    ]);
});

test('a movie without collected metadata still answers', function () {
    $movie = frontPageMovie();

    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/movies/'.$movie->id)
        ->assertOk()
        ->assertJsonPath('data.id', $movie->id)
        ->assertJsonPath('data.length', null)
        ->assertJsonPath('data.subtitles', [])
        ->assertJsonPath('data.comments_count', 0);
});

test('an unknown movie is not found', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/movies/999999')->assertNotFound();
});
