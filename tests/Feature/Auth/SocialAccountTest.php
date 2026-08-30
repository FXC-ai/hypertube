<?php

use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;


test('a user can create an account via github', function () {
    $githubUser = (new SocialiteUser())->map([
        'id' => 'github-user-123',
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => 'octocat@example.com',
        'avatar' => null,
    ])
        ->setToken('github-access-token')
        ->setRefreshToken('github-refresh-token');

    $githubProvider = Mockery::mock(Provider::class);

    $githubProvider
        ->shouldReceive('user')
        ->once()
        ->andReturn($githubUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('github')
        ->andReturn($githubProvider);

    $response = $this->get(route('socialite.callback', [
        'provider' => 'github',
    ]));

    $user = App\Models\User::query()
        ->where('email', 'octocat@example.com')
        ->first();

    expect($user)
        ->not->toBeNull()
        ->and($user->username)->toBe('octocat')
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'github-user-123',
        'token' => 'github-access-token',
        'refresh_token' => 'github-refresh-token',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});


test('a user can create an account via fortytwo', function () {

    Socialite::fake('fortytwo', SocialiteUser::fake([
        'id' => 'fortytwo-user-123',
        'nickname' => 'marvin',
        'name' => 'Marvin FortyTwo',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'fortytwo-access-token',
        'refreshToken' => 'fortytwo-refresh-token',
    ]));

    $response = $this->get(route('socialite.callback', [
        'provider' => 'fortytwo',
    ]));

    $user = User::query()
        ->where('email', 'marvin@example.com')
        ->firstOrFail();

    $this->assertDatabaseHas('users', [
        'username' => 'marvin',
        'email' => 'marvin@example.com',
    ]);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'fortytwo',
        'provider_id' => 'fortytwo-user-123',
        'token' => 'fortytwo-access-token',
        'refresh_token' => 'fortytwo-refresh-token',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});

test('an existing user can sign in via fortytwo', function () {
    $user = User::factory()->create([
        'email' => 'marvin@example.com',
    ]);

    Socialite::fake('fortytwo', SocialiteUser::fake([
        'id' => 'fortytwo-user-123',
        'nickname' => 'marvin',
        'name' => 'Marvin FortyTwo',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'fortytwo-access-token',
        'refreshToken' => 'fortytwo-refresh-token',
    ]));

    $response = $this->get(route('socialite.callback', [
        'provider' => 'fortytwo',
    ]));

    $this->assertDatabaseCount('users', 1);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'fortytwo',
        'provider_id' => 'fortytwo-user-123',
        'token' => 'fortytwo-access-token',
        'refresh_token' => 'fortytwo-refresh-token',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});


test('an existing user can sign in via github', function () {
    $user = User::factory()->create([
        'email' => 'octocat@example.com',
    ]);

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-user-123',
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => 'octocat@example.com',
        'avatar' => null,
        'token' => 'github-access-token',
        'refreshToken' => 'github-refresh-token',
    ]));

    $response = $this->get(route('socialite.callback', [
        'provider' => 'github',
    ]));

    $this->assertDatabaseCount('users', 1);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'github-user-123',
        'token' => 'github-access-token',
        'refresh_token' => 'github-refresh-token',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});


test('a user created via fortytwo can sign in via github', function () {
    Socialite::fake('fortytwo', SocialiteUser::fake([
        'id' => 'fortytwo-user-123',
        'nickname' => 'marvin',
        'name' => 'Marvin FortyTwo',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'fortytwo-access-token',
        'refreshToken' => 'fortytwo-refresh-token',
    ]));

    $this->get(route('socialite.callback', [
        'provider' => 'fortytwo',
    ]))->assertRedirect('/dashboard');

    $user = User::query()
        ->where('email', 'marvin@example.com')
        ->firstOrFail();

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));

    $this->assertGuest();

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-user-456',
        'nickname' => 'marvin',
        'name' => 'Marvin FortyTwo',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'github-access-token',
        'refreshToken' => 'github-refresh-token',
    ]));

    $response = $this->get(route('socialite.callback', [
        'provider' => 'github',
    ]));

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('social_accounts', 2);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'fortytwo',
        'provider_id' => 'fortytwo-user-123',
    ]);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'github-user-456',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});

test('an existing user can sign in via github and then fortytwo', function () {
    $user = User::factory()->create([
        'email' => 'marvin@example.com',
    ]);

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-user-123',
        'nickname' => 'marvin',
        'name' => 'Marvin',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'github-access-token',
        'refreshToken' => 'github-refresh-token',
    ]));

    $this->get(route('socialite.callback', [
        'provider' => 'github',
    ]))->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));

    $this->assertGuest();

    Socialite::fake('fortytwo', SocialiteUser::fake([
        'id' => 'fortytwo-user-456',
        'nickname' => 'marvin',
        'name' => 'Marvin FortyTwo',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'fortytwo-access-token',
        'refreshToken' => 'fortytwo-refresh-token',
    ]));

    $response = $this->get(route('socialite.callback', [
        'provider' => 'fortytwo',
    ]));

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('social_accounts', 2);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'github-user-123',
    ]);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'fortytwo',
        'provider_id' => 'fortytwo-user-456',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});

test('a user created via github can sign in via fortytwo', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-user-123',
        'nickname' => 'marvin',
        'name' => 'Marvin',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'github-access-token',
        'refreshToken' => 'github-refresh-token',
    ]));

    $this->get(route('socialite.callback', [
        'provider' => 'github',
    ]))->assertRedirect('/dashboard');

    $user = User::query()
        ->where('email', 'marvin@example.com')
        ->firstOrFail();

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));

    $this->assertGuest();

    Socialite::fake('fortytwo', SocialiteUser::fake([
        'id' => 'fortytwo-user-456',
        'nickname' => 'marvin',
        'name' => 'Marvin FortyTwo',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'fortytwo-access-token',
        'refreshToken' => 'fortytwo-refresh-token',
    ]));

    $response = $this->get(route('socialite.callback', [
        'provider' => 'fortytwo',
    ]));

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('social_accounts', 2);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'github-user-123',
    ]);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'fortytwo',
        'provider_id' => 'fortytwo-user-456',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});

test('signing in twice with the same github account does not create duplicates', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-user-123',
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => 'octocat@example.com',
        'avatar' => null,
        'token' => 'first-github-access-token',
        'refreshToken' => 'first-github-refresh-token',
    ]));

    $this->get(route('socialite.callback', [
        'provider' => 'github',
    ]))->assertRedirect('/dashboard');

    $user = User::query()
        ->where('email', 'octocat@example.com')
        ->firstOrFail();

    $this->post(route('logout'));

    $this->assertGuest();

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-user-123',
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => 'octocat@example.com',
        'avatar' => null,
        'token' => 'new-github-access-token',
        'refreshToken' => 'new-github-refresh-token',
    ]));

    $response = $this->get(route('socialite.callback', [
        'provider' => 'github',
    ]));

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('social_accounts', 1);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'github-user-123',
        'token' => 'new-github-access-token',
        'refresh_token' => 'new-github-refresh-token',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});

test('signing in twice with the same fortytwo account does not create duplicates', function () {
    Socialite::fake('fortytwo', SocialiteUser::fake([
        'id' => 'fortytwo-user-123',
        'nickname' => 'marvin',
        'name' => 'Marvin FortyTwo',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'first-fortytwo-access-token',
        'refreshToken' => 'first-fortytwo-refresh-token',
    ]));

    $this->get(route('socialite.callback', [
        'provider' => 'fortytwo',
    ]))->assertRedirect('/dashboard');

    $user = User::query()
        ->where('email', 'marvin@example.com')
        ->firstOrFail();

    $this->post(route('logout'));

    $this->assertGuest();

    Socialite::fake('fortytwo', SocialiteUser::fake([
        'id' => 'fortytwo-user-123',
        'nickname' => 'marvin',
        'name' => 'Marvin FortyTwo',
        'email' => 'marvin@example.com',
        'avatar' => null,
        'token' => 'new-fortytwo-access-token',
        'refreshToken' => 'new-fortytwo-refresh-token',
    ]));

    $response = $this->get(route('socialite.callback', [
        'provider' => 'fortytwo',
    ]));

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('social_accounts', 1);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'fortytwo',
        'provider_id' => 'fortytwo-user-123',
        'token' => 'new-fortytwo-access-token',
        'refresh_token' => 'new-fortytwo-refresh-token',
    ]);

    $this->assertAuthenticatedAs($user);

    $response->assertRedirect('/dashboard');
});

test(
    'sign in is refused when the provider does not provide an email address',
    function (string $provider, string $providerId) {
        Socialite::fake($provider, SocialiteUser::fake([
            'id' => $providerId,
            'nickname' => 'marvin',
            'name' => 'Marvin',
            'email' => null,
            'avatar' => null,
        ]));

        $response = $this->get(route('socialite.callback', [
            'provider' => $provider,
        ]));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('social');
    },
)->with([
    'GitHub' => [
        'github',
        'github-user-without-email',
    ],
    '42' => [
        'fortytwo',
        'fortytwo-user-without-email',
    ],
]);
