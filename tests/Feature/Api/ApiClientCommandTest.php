<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;

beforeEach(function () {
    $this->environmentDirectory = sys_get_temp_dir().'/hypertube-env-'.uniqid();
    mkdir($this->environmentDirectory);
    $this->app->useEnvironmentPath($this->environmentDirectory);
});

afterEach(function () {
    @unlink($this->environmentDirectory.'/.env');
    @rmdir($this->environmentDirectory);
});

function tokenFor(string $clientId, string $clientSecret): TestResponse
{
    return test()->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'username' => 'player',
        'password' => 'password',
    ]);
}

test('without a client in .env, one is created and written to .env', function () {
    config(['passport.api_client' => ['id' => null, 'secret' => null]]);
    file_put_contents($this->environmentDirectory.'/.env', "APP_NAME=Hypertube\nPASSPORT_API_CLIENT_ID=\n");
    User::factory()->create(['username' => 'player']);

    $this->artisan('api:client')->assertSuccessful();

    $environment = file_get_contents($this->environmentDirectory.'/.env');
    preg_match('/^PASSPORT_API_CLIENT_ID=(.+)$/m', $environment, $id);
    preg_match('/^PASSPORT_API_CLIENT_SECRET=(.+)$/m', $environment, $secret);

    expect($environment)->toStartWith("APP_NAME=Hypertube\n")
        ->and(substr_count($environment, 'PASSPORT_API_CLIENT_ID='))->toBe(1)
        ->and(Client::query()->whereKey($id[1])->exists())->toBeTrue();
    tokenFor($id[1], $secret[1])->assertOk();
});

test('a client named by .env but missing from the database is recreated with the same credentials', function () {
    $id = '01a187ec-89c7-7073-bd7a-aa9baad79c8a';
    config(['passport.api_client' => ['id' => $id, 'secret' => 'the-secret-from-env']]);
    User::factory()->create(['username' => 'player']);

    $this->artisan('api:client')->assertSuccessful();

    expect(Client::query()->count())->toBe(1)
        ->and(file_exists($this->environmentDirectory.'/.env'))->toBeFalse();
    tokenFor($id, 'the-secret-from-env')->assertOk();
});

test('an existing client is left alone', function () {
    $id = '01a187ec-89c7-7073-bd7a-aa9baad79c8a';
    config(['passport.api_client' => ['id' => $id, 'secret' => 'the-secret-from-env']]);

    $this->artisan('api:client')->assertSuccessful();
    $this->artisan('api:client')->assertSuccessful();

    expect(Client::query()->count())->toBe(1);
});
