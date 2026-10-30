<?php

use App\Enums\Languages;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * Every other test builds its users with this factory: a broken default there would
 * hide behind a hundred green tests, so the defaults are checked here directly.
 */
test('a generated user carries a usable username and the english preference the subject asks for', function () {
    $user = User::factory()->create();

    expect($user->username)->toMatch('/^[a-z0-9._-]+$/')
        ->and($user->firstname)->not->toBeNull()
        ->and($user->lastname)->not->toBeNull()
        ->and($user->preferredlanguage)->toBe(Languages::English);
});

test('the usernames stay unique across a batch', function () {
    $usernames = User::factory()->count(30)->create()->pluck('username');

    expect($usernames)->toHaveCount(30)
        ->and($usernames->unique())->toHaveCount(30);
});

test('the password is stored hashed but still answers to the fixture password', function () {
    $user = User::factory()->create();

    expect($user->getRawOriginal('password'))->not->toBe('password')
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

test('a user can be built without a password, as an omniauth account is', function () {
    expect(User::factory()->create(['password' => null])->getRawOriginal('password'))->toBeNull();
});

test('the unverified state leaves the account waiting for its e-mail', function () {
    expect(User::factory()->unverified()->create()->email_verified_at)->toBeNull();
});

test('the two-factor state stores readable secrets and a confirmed timestamp', function () {
    // Built without saving: this installation does not enable Fortify's two-factor
    // feature, so the users table has no column for those secrets.
    $user = User::factory()->withTwoFactor()->make();

    expect(decrypt((string) $user->getAttribute('two_factor_secret')))
        ->toBe('the-secret-of-a-confirmed-two-factor-setup')
        ->and(json_decode((string) decrypt($user->getAttribute('two_factor_recovery_codes')), true))
        ->toBe(['recovery-code-1'])
        ->and($user->two_factor_confirmed_at)->not->toBeNull();
});
