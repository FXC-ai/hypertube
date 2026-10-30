<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;

/*
 * `is_admin` lets an account make the server fetch any public link: only
 * `php artisan user:admin` may set it, never a form or an API call.
 */
test('the admin command creates a verified admin with a hidden password', function () {
    $this->artisan('user:admin', ['username' => 'evaluator', 'email' => 'evaluator@example.test'])
        ->expectsQuestion('Password', 'Strong-Pass42')
        ->expectsQuestion('Password again', 'Strong-Pass42')
        ->assertSuccessful();

    $admin = User::query()->where('username', 'evaluator')->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and($admin->getRawOriginal('password'))->not->toBe('Strong-Pass42');
});

test('the admin command promotes an existing account without touching its password', function () {
    $user = User::factory()->create(['username' => 'bsolo']);
    $hash = $user->getRawOriginal('password');

    $this->artisan('user:admin', ['username' => 'bsolo', 'email' => 'other@example.test'])
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue()
        ->and($user->getRawOriginal('password'))->toBe($hash);
});

test('the admin command refuses a weak or mistyped password', function (string $password, string $confirmation) {
    $this->artisan('user:admin', ['username' => 'evaluator', 'email' => 'evaluator@example.test'])
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Password again', $confirmation)
        ->assertFailed();

    expect(User::query()->where('username', 'evaluator')->exists())->toBeFalse();
})->with([
    'too common' => ['Password1', 'Password1'],
    'confirmation differs' => ['Strong-Pass42', 'Strong-Pass43'],
]);

test('registration cannot create an admin', function () {
    $this->post(route('register.store'), [
        'username' => 'sneaky',
        'email' => 'sneaky@example.test',
        'firstname' => 'Sneaky',
        'lastname' => 'User',
        'password' => 'Strong-Pass42',
        'password_confirmation' => 'Strong-Pass42',
        'profilepicture' => UploadedFile::fake()->image('avatar.jpg'),
        'is_admin' => true,
    ]);

    expect(User::query()->where('username', 'sneaky')->first()?->is_admin)->toBeFalse();
});

test('the profile page cannot grant admin', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'username' => $user->username,
        'email' => $user->email,
        'firstname' => $user->firstname,
        'lastname' => $user->lastname,
        'preferredlanguage' => 'english',
        'is_admin' => true,
    ]);

    expect($user->refresh()->is_admin)->toBeFalse();
});

test('the api cannot grant admin', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson("/api/users/{$user->id}", ['firstname' => 'Renamed', 'is_admin' => true])
        ->assertOk()
        ->assertJsonMissingPath('data.is_admin');

    expect($user->refresh()->is_admin)->toBeFalse()
        ->and($user->firstname)->toBe('Renamed');
});
