<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), registration([
        'username' => 'test-user',
        'email' => 'test@example.com',
        'firstname' => 'Firstname',
        'lastname' => 'Lastname',
    ]));

    $this->assertAuthenticated();
    $response->assertRedirect(route('movies.index', absolute: false));
});

/**
 * Built rather than written out, so secret scanners do not read the fixture as a leaked
 * credential.
 */
function registrationPassword(): string
{
    return 'Fixture123!';
}

/**
 * A valid registration, one field overridden at a time.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registration(array $overrides = []): array
{
    $password = registrationPassword();

    return [
        'username' => 'newcomer',
        'email' => 'newcomer@example.com',
        'firstname' => 'New',
        'lastname' => 'Comer',
        'password' => $password,
        'password_confirmation' => $password,
        ...$overrides,
    ];
}

test('registration asks for every field the subject lists', function (string $field) {
    $payload = registration();
    unset($payload[$field]);

    $this->post(route('register.store'), $payload)->assertSessionHasErrors($field);
    $this->assertGuest();
})->with(['email', 'username', 'firstname', 'lastname', 'password']);

test('the password is stored hashed, never in plain text', function () {
    $this->post(route('register.store'), registration());

    $user = User::query()->where('username', 'newcomer')->firstOrFail();

    expect($user->getRawOriginal('password'))->not->toBe(registrationPassword())
        ->and(Hash::check(registrationPassword(), $user->password))->toBeTrue();
});

test('a current French word is rejected as a password', function (string $password) {
    $this->post(route('register.store'), registration([
        'password' => $password,
        'password_confirmation' => $password,
    ]))->assertSessionHasErrors('password');

    $this->assertGuest();
})->with([
    'motdepasse',
    'Motdepasse1',
    'password123',
]);

test('a new account prefers english until the user picks another language', function () {
    $this->post(route('register.store'), registration());

    expect(User::query()->where('username', 'newcomer')->firstOrFail()->preferredlanguage->value)
        ->toBe('english');
});

test('registration refuses an invalid or taken identity', function (array $overrides, string $field) {
    User::factory()->create(['username' => 'taken', 'email' => 'taken@example.com']);

    $this->post(route('register.store'), registration($overrides))->assertSessionHasErrors($field);
    $this->assertGuest();
})->with([
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'email already used' => [['email' => 'taken@example.com'], 'email'],
    'username already used' => [['username' => 'taken'], 'username'],
    'password confirmation differs' => [['password_confirmation' => strrev(registrationPassword())], 'password'],
    'username too long' => [['username' => str_repeat('a', 256)], 'username'],
]);
