<?php

use App\Models\Comment;
use App\Models\Movie;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Every page of the app opens for a signed-in user. The subject only requires authentication
 * for the library and the video pages, so email verification is not a gate.
 */
test('every page renders for a signed-in user', function (string $route, string $component) {
    $user = User::factory()->create();
    $movie = Movie::factory()->create();

    $uri = strtr($route, ['{movie}' => $movie->id, '{user}' => $user->id]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/movies', 'movies/index'],
    ['/movies/{movie}', 'movies/show'],
    ['/movies/{movie}/conversion', 'movies/show'],
    ['/users', 'users/index'],
    ['/users/{user}', 'users/show'],
    ['/settings/profile', 'settings/profile'],
    ['/settings/sources', 'settings/sources'],
    ['/settings/security', 'settings/security'],
    ['/settings/appearance', 'settings/appearance'],
    ['/user/confirm-password', 'auth/confirm-password'],
]);

test('settings opens on the profile page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings')
        ->assertRedirect('/settings/profile');
});

test('an authenticated account whose email is not verified still opens the app pages', function (string $uri) {
    $user = User::factory()->unverified()->create();
    $movie = Movie::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(strtr($uri, ['{movie}' => $movie->id, '{user}' => $user->id]))
        ->assertOk();
})->with([
    '/movies',
    '/movies/{movie}',
    '/users',
    '/users/{user}',
    '/settings/security',
    '/settings/appearance',
]);

test('the guest pages send a signed-in user away', function (string $uri) {
    $this->actingAs(User::factory()->create())
        ->get($uri)
        ->assertRedirect();
})->with(['/login', '/register', '/forgot-password']);

test('an unknown record answers 404 on the web pages', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create())
        ->call($method, $uri)
        ->assertNotFound();
})->with([
    ['GET', '/movies/999999'],
    ['POST', '/movies/999999/comments'],
    ['GET', '/movies/999999/conversion'],
    ['GET', '/users/999999'],
    ['DELETE', '/comments/999999'],
]);

test('a comment is deleted on the web only by its author', function () {
    $comment = Comment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete("/comments/{$comment->id}")
        ->assertForbidden();
    $this->assertModelExists($comment);
});

test('a verb a page does not support answers 405', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create())
        ->call($method, $uri)
        ->assertStatus(405);
})->with([
    ['POST', '/movies'],
    ['DELETE', '/users'],
    ['PUT', '/settings/sources'],
]);

test('the comments of a movie are only listed on its page', function () {
    $movie = Movie::factory()->create();

    // GET /movies/{movie}/comments rendered pages that do not exist and always answered 500.
    $this->actingAs(User::factory()->create())
        ->get("/movies/{$movie->id}/comments")
        ->assertStatus(405);
});

test('a password is confirmed with the right password only', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/user/confirm-password')
        ->post('/user/confirm-password', ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->post('/user/confirm-password', ['password' => 'password'])
        ->assertRedirect()
        ->assertSessionHas('auth.password_confirmed_at');
});
