<?php

use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

function passwordGrantClient(): Client
{
    return (new ClientRepository)->createPasswordGrantClient('Hypertube API', provider: 'users', confidential: true);
}

test('the token endpoint issues an access token for a client and its secret', function () {
    $client = passwordGrantClient();
    User::factory()->create(['email' => 'player@example.test']);

    $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
        'username' => 'player@example.test',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);
});

test('a username is accepted in place of the email address', function () {
    $client = passwordGrantClient();
    User::factory()->create(['username' => 'player']);

    $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
        'username' => 'player',
        'password' => 'password',
    ])->assertOk()
        ->assertJson(['token_type' => 'Bearer']);
});

test('the issued token is what unlocks the api', function () {
    $client = passwordGrantClient();
    $user = User::factory()->create();

    $token = $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
        'username' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken((string) $token)
        ->getJson('/api/users')
        ->assertOk();
});

test('a token request without the client secret is refused', function () {
    $client = passwordGrantClient();
    $user = User::factory()->create();

    $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'username' => $user->email,
        'password' => 'password',
    ])->assertStatus(400);
});

test('a token request carrying a wrong client secret is refused', function () {
    $client = passwordGrantClient();
    $user = User::factory()->create();

    $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'client_secret' => 'not-the-secret',
        'username' => $user->email,
        'password' => 'password',
    ])->assertStatus(401);
});

test('a token request carrying a wrong password is refused', function () {
    $client = passwordGrantClient();
    $user = User::factory()->create();

    $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
        'username' => $user->email,
        'password' => 'not-the-password',
    ])->assertStatus(400);
});

test('a client that was not given the password grant cannot issue a token', function () {
    $client = (new ClientRepository)->createClientCredentialsGrantClient('Machine only');
    $user = User::factory()->create();

    $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
        'username' => $user->email,
        'password' => 'password',
    ])->assertStatus(400);
});

test('passport only exposes the token route', function (string $method, string $uri) {
    $this->json($method, $uri)->assertNotFound();
})->with([
    ['GET', '/oauth/authorize'],
    ['POST', '/oauth/authorize'],
    ['GET', '/oauth/device'],
    ['POST', '/oauth/device/code'],
    ['POST', '/oauth/token/refresh'],
]);

test('a refresh token is exchanged at the token route', function () {
    $client = passwordGrantClient();
    User::factory()->create(['username' => 'player']);

    $refreshToken = $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
        'username' => 'player',
        'password' => 'password',
    ])->json('refresh_token');

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
    ])->assertOk()
        ->assertJsonStructure(['access_token', 'refresh_token']);
});
