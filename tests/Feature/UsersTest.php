<?php

use App\Models\User;
use App\Enums\Languages;

test('an authenticated user can view another public user profile', function () {
    $authenticatedUser = User::factory()->create();
    $viewedUser = User::factory()->create([
        'username' => 'john-doe',
        'firstname' => 'John',
        'lastname' => 'Doe',
    ]);

    $response = $this
        ->actingAs($authenticatedUser)
        ->get(route('users.show', $viewedUser));

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $viewedUser->id)
        ->assertJsonPath('data.username', 'john-doe')
        ->assertJsonPath('data.firstname', 'John')
        ->assertJsonPath('data.lastname', 'Doe')
        ->assertJsonMissingPath('data.email')
        ->assertJsonMissingPath('data.password')
        ->assertJsonPath(
            'data.preferredlanguage',
            Languages::English->value,
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
        ->assertJsonMissingPath('data.email')
        ->assertJsonMissingPath('data.email_verified_at')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.two_factor_secret')
        ->assertJsonMissingPath('data.two_factor_recovery_codes');
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
        ->assertJsonPath('data.id', $viewedUser->id)
        ->assertJsonPath('data.username', 'viewed-user');
});
