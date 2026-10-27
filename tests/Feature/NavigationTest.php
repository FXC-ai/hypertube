<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login when opening the movie library', function () {
    $this->get(route('movies.index'))->assertRedirect(route('login'));
});

test('authenticated users can visit the movie library', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('movies.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('movies/index'));
});

test('the removed dashboard page is not available', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertNotFound();
});
