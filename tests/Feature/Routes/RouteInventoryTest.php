<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * Every route of the app with what a guest gets. A new route fails the first test until it is
 * listed here, so nobody adds an endpoint without deciding who may reach it.
 *
 * login: redirected to the login page (web session). 401: JSON refusal (API token).
 * public: reachable without an account, its behaviour is covered by its own tests.
 */
function routeInventory(): array
{
    return [
        // Public pages and endpoints.
        'GET /' => 'public',
        'GET up' => 'public',
        'GET login' => 'public',
        'POST login' => 'public',
        'GET register' => 'public',
        'POST register' => 'public',
        'GET forgot-password' => 'public',
        'POST forgot-password' => 'public',
        'GET reset-password/{token}' => 'public',
        'POST reset-password' => 'public',
        'GET auth/{provider}/redirect' => 'public',
        'GET auth/{provider}/callback' => 'public',
        'POST resend/webhook' => 'public',
        'POST oauth/token' => 'public',
        'GET api' => 'public',
        'GET api/movies' => 'public',
        'GET api/documentation' => 'public',
        'GET api/oauth2-callback' => 'public',
        'GET docs' => 'public',
        'GET docs/asset/{asset}' => 'public',

        // Web pages behind the session.
        'POST logout' => 'login',
        'GET email/verify' => 'login',
        'GET email/verify/{id}/{hash}' => 'login',
        'POST email/verification-notification' => 'login',
        'GET user/confirm-password' => 'login',
        'POST user/confirm-password' => 'login',
        'GET user/confirmed-password-status' => 'login',
        'GET movies' => 'login',
        'GET movies/{movie}' => 'login',
        'POST movies/import' => 'login',
        'GET movies/sources/{source}/imdb/{imdb_id}' => 'login',
        'POST movies/{movie}/conversion' => 'login',
        'GET movies/{movie}/conversion' => 'login',
        'POST movies/{movie}/comments' => 'login',
        'DELETE comments/{comment}' => 'login',
        'GET users' => 'login',
        'GET users/{user}' => 'login',
        'PATCH updateavatar' => 'login',
        'GET settings' => 'login',
        'GET settings/profile' => 'login',
        'PATCH settings/profile' => 'login',
        'DELETE settings/profile' => 'login',
        'GET settings/sources' => 'login',
        'PATCH settings/sources' => 'login',
        'GET settings/security' => 'login',
        'PUT settings/password' => 'login',
        'GET settings/appearance' => 'login',

        // REST API behind an OAuth2 token.
        'GET api/movies/{movie}' => '401',
        'GET api/movies/{movie}/comments' => '401',
        'POST api/movies/{movie}/comments' => '401',
        'GET api/users' => '401',
        'GET api/users/{user}' => '401',
        'PATCH api/users/{user}' => '401',
        'GET api/comments' => '401',
        'POST api/comments' => '401',
        'GET api/comments/{comment}' => '401',
        'PATCH api/comments/{comment}' => '401',
        'DELETE api/comments/{comment}' => '401',
    ];
}

/**
 * Development tooling registered by packages only when debugging locally.
 */
function isDevelopmentRoute(string $uri): bool
{
    return str_starts_with($uri, '_boost/')
        || str_starts_with($uri, '_inertia/')
        || str_starts_with($uri, '_ignition/')
        || str_starts_with($uri, 'storage/');
}

/**
 * @return list<string> "METHOD uri", one per method (HEAD aside, it follows GET).
 */
function registeredRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $route): bool => isDevelopmentRoute($route->uri()))
        ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            // Route::redirect('settings', ...) answers every verb; GET is the one that matters.
            ->when($route->uri() === 'settings', fn ($methods) => $methods->intersect(['GET']))
            ->map(fn (string $method): string => "{$method} {$route->uri()}"))
        ->unique()
        ->sort()
        ->values()
        ->all();
}

/**
 * A request a guest can send to the route: ids are 1, other parameters a plausible value.
 */
function guestUri(string $uri): string
{
    return '/'.ltrim(strtr($uri, [
        '{movie}' => '1',
        '{user}' => '1',
        '{comment}' => '1',
        '{id}' => '1',
        '{hash}' => sha1('nobody@example.test'),
        '{source}' => 'wikidata',
        '{imdb_id}' => 'tt0032599',
    ]), '/');
}

test('every route of the app is in the inventory', function () {
    expect(registeredRoutes())->toEqualCanonicalizing(array_keys(routeInventory()));
});

test('a guest is sent to the login page by every web route behind the session', function (string $route) {
    [$method, $uri] = explode(' ', $route, 2);

    $this->call($method, guestUri($uri))->assertRedirect(route('login'));
})->with(fn () => array_keys(array_filter(routeInventory(), fn ($access) => $access === 'login')));

test('a guest is refused with 401 by every api route behind a token', function (string $route) {
    [$method, $uri] = explode(' ', $route, 2);

    $this->json($method, guestUri($uri))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
})->with(fn () => array_keys(array_filter(routeInventory(), fn ($access) => $access === '401')));

test('the public pages render for a guest', function (string $uri) {
    $this->get($uri)->assertOk();
})->with([
    '/',
    '/up',
    '/login',
    '/register',
    '/forgot-password',
    '/reset-password/a-token',
    '/api',
    '/api/movies',
    '/api/documentation',
]);
