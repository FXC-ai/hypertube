<?php

use App\Support\SocialiteProviders;

/*
 * Subject III.1: registration and login must both be possible through Omniauth, with at
 * least the 42 strategy plus one freely chosen strategy.
 */
test('the login page and the register page are handed every omniauth strategy', function (string $route) {
    $this->get(route($route))->assertOk()->assertInertia(fn ($page) => $page
        ->component(str_contains($route, 'login') ? 'auth/login' : 'auth/register')
        ->where('providers.fortytwo', '42')
        ->where('providers.github', 'GitHub'));
})->with(['login', 'register']);

test('two strategies are implemented and one of them is 42', function () {
    expect(SocialiteProviders::values())->toContain('fortytwo')
        ->and(count(SocialiteProviders::values()))->toBeGreaterThanOrEqual(2);
});

test('only the implemented strategies are answered by the omniauth routes', function () {
    $this->get('/auth/notaprovider/redirect')->assertNotFound();
    $this->get('/auth/github/redirect')->assertRedirect();
})->skip(fn (): bool => config('services.github.client_id') === null, 'GitHub is not configured');
