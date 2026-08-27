<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;
use App\Enums\Languages;

test('an authenticated user can view another public user profile', function () {
    $authenticatedUser = User::factory()->create();
    $viewedUser = User::factory()->create([
        'username' => 'john-doe',
        'firstname' => 'John',
        'lastname' => 'Doe',
        'preferredlanguage' => Languages::English,
    ]);

    $response = $this
        ->actingAs($authenticatedUser)
        ->get(route('users.show', $viewedUser));

    $response->assertOk();

    $response->assertInertia(

        function (AssertableInertia $page) use ($viewedUser) {
            $page->component('users/show');
            $page->where('user.data.id', $viewedUser->id);
            $page->where('user.data.username', 'john-doe');
            $page->where('user.data.firstname', 'John');
            $page->where('user.data.lastname', 'Doe');
            $page->where('user.data.preferredlanguage', Languages::English->value);

            $page->missing('user.data.email');
            $page->missing('user.data.password');
        }

    );
});

test('a missing user returns a not found response', function () {
    $authenticatedUser = User::factory()->create();

    $this
        ->actingAs($authenticatedUser)
        ->get(route('users.show', ['user' => 999999]))
        ->assertNotFound();
});

test('the public profile does not expose private user information', function () {
    $authenticatedUser = User::factory()->create();
    $viewedUser = User::factory()->create();

    $this
        ->actingAs($authenticatedUser)
        ->get(route('users.show', $viewedUser))
        ->assertOk()
        ->assertInertia(
            fn(AssertableInertia $page) => $page
                ->component('users/show')
                ->has(
                    'user.data',
                    fn(AssertableInertia $user) => $user
                        ->missing('email')
                        ->missing('email_verified_at')
                        ->missing('password')
                        ->missing('remember_token')
                        ->missing('two_factor_secret')
                        ->missing('two_factor_recovery_codes')
                        ->etc()
                )
        );
});

test('the route returns the requested user instead of the authenticated user', function () {
    $authenticatedUser = User::factory()->create([
        'username' => 'authenticated-user',
    ]);

    $viewedUser = User::factory()->create([
        'username' => 'viewed-user',
    ]);

    $this
        ->actingAs($authenticatedUser)
        ->get(route('users.show', $viewedUser))
        ->assertOk()
        ->assertInertia(
            fn(AssertableInertia $page) => $page
                ->component('users/show')
                ->has(
                    'user.data',
                    fn(AssertableInertia $user) => $user
                        ->where('id', $viewedUser->id)
                        ->where('username', 'viewed-user')
                        ->etc()
                )
        );
});
