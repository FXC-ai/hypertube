<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Ids are bigint columns. PostgreSQL rejects "abc", 1.5 or 99999999999999999999 with an error,
 * which Laravel turns into a 500 (with the SQL in debug mode). SQLite, used by the tests,
 * accepts them silently, so what is checked is that no route matches: such ids never reach
 * the database, on any driver.
 */
function invalidIdRequests(): array
{
    $routes = [
        'GET movies/{movie}',
        'POST movies/{movie}/conversion',
        'GET movies/{movie}/conversion',
        'POST movies/{movie}/comments',
        'DELETE comments/{comment}',
        'GET users/{user}',
        'GET api/movies/{movie}',
        'GET api/movies/{movie}/comments',
        'POST api/movies/{movie}/comments',
        'GET api/users/{user}',
        'PATCH api/users/{user}',
        'GET api/comments/{comment}',
        'PATCH api/comments/{comment}',
        'DELETE api/comments/{comment}',
    ];
    $requests = [];

    foreach ($routes as $route) {
        [$method, $uri] = explode(' ', $route);

        foreach (['abc', '1.5', '-1', '99999999999999999999', '1e3'] as $id) {
            $path = '/'.preg_replace('/\{(movie|user|comment)\}/', $id, $uri);
            $requests["{$method} {$path}"] = [$method, $path];
        }
    }

    return $requests;
}

test('an id that is not a bigint matches no route', function (string $method, string $path) {
    expect(fn () => Route::getRoutes()->match(Request::create($path, $method)))
        ->toThrow(NotFoundHttpException::class);
})->with(invalidIdRequests());

test('an id that is not a bigint answers 404 to a signed-in user', function (string $method, string $path) {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->actingAs($user)->json($method, $path)->assertNotFound();
})->with(invalidIdRequests());

test('the largest id that fits is still routed', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/movies/999999999999999999')->assertNotFound()
        ->assertJsonMissing(['exception' => QueryException::class]);
    expect(Route::getRoutes()->match(Request::create('/api/movies/999999999999999999'))->getName())
        ->toBe('api.movies.show');
});
