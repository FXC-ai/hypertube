<?php

use App\Models\Comment;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('the comment listing asks for an access token', function () {
    Comment::factory()->count(2)->create();

    $this->getJson('/api/comments')->assertUnauthorized();
});

test('the comment listing gives the content, its author and its date', function () {
    Comment::factory()->create([
        'user_id' => User::factory()->create(['username' => 'critic'])->id,
        'content' => 'A worthy film',
    ]);

    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/comments')
        ->assertOk()
        ->assertJsonPath('data.0.content', 'A worthy film')
        ->assertJsonPath('data.0.username', 'critic')
        ->assertJsonPath('data.0.created_at', fn (?string $date): bool => $date !== null);
});

test('the comments of a movie are read through its own route', function () {
    $movie = Movie::factory()->create();
    Comment::factory()->create([
        'movie_id' => $movie->id,
        'user_id' => User::factory()->create(['username' => 'critic'])->id,
    ]);
    Comment::factory()->create();

    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/movies/'.$movie->id.'/comments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.username', 'critic');
});

test('a comment is read by its id', function () {
    $comment = Comment::factory()->create([
        'user_id' => User::factory()->create(['username' => 'critic'])->id,
        'content' => 'Only this one',
    ]);

    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/comments/'.$comment->id)
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $comment->id,
                'movie_id' => $comment->movie_id,
                'content' => 'Only this one',
                'username' => 'critic',
            ],
        ]);
});

test('a comment is posted with the movie it comments', function () {
    $movie = Movie::factory()->create();
    Passport::actingAs(User::factory()->create(['username' => 'critic']));

    $this->postJson('/api/comments', ['comment' => 'A fine movie', 'movie_id' => $movie->id])
        ->assertCreated()
        ->assertJson([
            'data' => ['content' => 'A fine movie', 'movie_id' => $movie->id, 'username' => 'critic'],
        ]);

    expect($movie->fresh()->comments)->toHaveCount(1);
});

test('a comment is posted on the movie named in the path', function () {
    $movie = Movie::factory()->create();
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/movies/'.$movie->id.'/comments', ['comment' => 'Path named movie'])
        ->assertCreated()
        ->assertJson(['data' => ['movie_id' => $movie->id, 'content' => 'Path named movie']]);
});

test('a posted comment naming another movie than its path is rejected', function () {
    $movie = Movie::factory()->create();
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/movies/'.$movie->id.'/comments', [
        'comment' => 'Sneaky',
        'movie_id' => Movie::factory()->create()->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['movie_id']);
});

test('a comment cannot be posted without its text or its movie', function () {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/comments', ['movie_id' => Movie::factory()->create()->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['comment']);

    $this->postJson('/api/comments', ['comment' => 'Orphan'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['movie_id']);

    $this->postJson('/api/comments', ['comment' => 'Ghost', 'movie_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['movie_id']);
});

test('the author rewrites their comment', function () {
    $author = User::factory()->create(['username' => 'critic']);
    $comment = Comment::factory()->create(['user_id' => $author->id, 'content' => 'Before']);

    Passport::actingAs($author);

    $this->patchJson('/api/comments/'.$comment->id, ['comment' => 'After', 'username' => 'someone-else'])
        ->assertOk()
        ->assertJson(['data' => ['content' => 'After', 'username' => 'critic']]);

    expect($comment->fresh()->content)->toBe('After');
    expect($author->fresh()->username)->toBe('critic');
});

test('a comment may not be rewritten by someone else', function () {
    $comment = Comment::factory()->create();
    Passport::actingAs(User::factory()->create());

    $this->patchJson('/api/comments/'.$comment->id, ['comment' => 'Hijacked'])->assertForbidden();

    expect($comment->fresh()->content)->not->toBe('Hijacked');
});

test('the author deletes their comment', function () {
    $author = User::factory()->create();
    $comment = Comment::factory()->create(['user_id' => $author->id]);

    Passport::actingAs($author);

    $this->deleteJson('/api/comments/'.$comment->id)->assertNoContent();

    expect(Comment::find($comment->id))->toBeNull();
});

test('a comment may not be deleted by someone else', function () {
    $comment = Comment::factory()->create();
    Passport::actingAs(User::factory()->create());

    $this->deleteJson('/api/comments/'.$comment->id)->assertForbidden();

    expect(Comment::find($comment->id))->not->toBeNull();
});

test('an unknown comment is not found', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/comments/999999')->assertNotFound();
});

test('an undocumented api path answers not found', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/not-a-route')->assertNotFound();
});

test('a method the resource does not support answers method not allowed', function () {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/users')->assertStatus(405);
    $this->putJson('/api/users')->assertStatus(405);
    $this->deleteJson('/api/comments')->assertStatus(405);
});

test('the api root describes itself', function () {
    $this->getJson('/api')
        ->assertOk()
        ->assertJsonStructure(['name', 'documentation']);
});

test('an id that is not a bigint answers not found instead of reaching the database', function (string $method, string $uri) {
    Passport::actingAs(User::factory()->create());

    // SQLite would also answer 404 for these ids, PostgreSQL answers 500: what matters is that
    // no route matches them, so they never reach the database.
    expect(fn () => Route::getRoutes()->match(Request::create($uri, $method)))
        ->toThrow(NotFoundHttpException::class);
    $this->json($method, $uri)->assertNotFound();
})->with([
    ['GET', '/api/movies/abc'],
    ['GET', '/api/movies/1.5'],
    ['GET', '/api/movies/99999999999999999999'],
    ['GET', '/api/movies/abc/comments'],
    ['POST', '/api/movies/abc/comments'],
    ['GET', '/api/users/abc'],
    ['PATCH', '/api/users/99999999999999999999'],
    ['GET', '/api/comments/abc'],
    ['PATCH', '/api/comments/1.5'],
    ['DELETE', '/api/comments/99999999999999999999'],
]);
