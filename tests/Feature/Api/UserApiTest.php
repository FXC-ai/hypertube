<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;

test('the user listing asks for an access token', function () {
    $this->getJson('/api/users')->assertUnauthorized();
});

test('the user listing gives the id and the username of every account', function () {
    Passport::actingAs(User::factory()->create(['username' => 'player']));

    $this->getJson('/api/users')->assertOk()->assertJsonStructure([
        'data' => [['id', 'username']],
        'links' => ['first', 'last', 'prev', 'next'],
        'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
    ]);
});

test('a profile gives its username and the link of its picture', function () {
    $profile = User::factory()->create([
        'username' => 'player',
        'profilepicture' => 'avatars/player.png',
    ]);
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/users/'.$profile->id)
        ->assertOk()
        ->assertJsonPath('data.id', $profile->id)
        ->assertJsonPath('data.username', 'player')
        ->assertJsonPath('data.profilepicture', fn (string $url): bool => str_ends_with($url, '/storage/avatars/player.png'));
});

test('a profile without a picture answers a null link', function () {
    $profile = User::factory()->create(['profilepicture' => null]);
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/users/'.$profile->id)
        ->assertOk()
        ->assertJsonPath('data.profilepicture', null);
});

test('the email address of a profile stays private to its owner', function () {
    $profile = User::factory()->create(['email' => 'hidden@example.test']);

    Passport::actingAs(User::factory()->create());
    $this->getJson('/api/users/'.$profile->id)->assertOk()->assertJsonMissingPath('data.email');

    Passport::actingAs($profile);
    $this->getJson('/api/users/'.$profile->id)
        ->assertOk()
        ->assertJsonPath('data.email', 'hidden@example.test');
});

test('an owner patches their own profile', function () {
    $user = User::factory()->create(['username' => 'player']);
    Passport::actingAs($user);

    $this->patchJson('/api/users/'.$user->id, [
        'username' => 'newplayer',
        'firstname' => 'Jean',
        'lastname' => 'Dupont',
    ])->assertOk()->assertJsonPath('data.username', 'newplayer');

    expect($user->fresh())->toMatchArray([
        'username' => 'newplayer',
        'firstname' => 'Jean',
        'lastname' => 'Dupont',
    ]);
});

test('a password sent to the api is stored hashed', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/users/'.$user->id, ['password' => 'ApiStrong123!'])
        ->assertOk()
        ->assertJsonMissingPath('data.password');

    expect(Hash::check('ApiStrong123!', $user->fresh()->password))->toBeTrue();
});

test('a changed email has to be verified again', function () {
    $user = User::factory()->create(['email' => 'old@example.test']);
    Passport::actingAs($user);

    $this->patchJson('/api/users/'.$user->id, ['email' => 'new@example.test'])->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('another user profile may not be patched', function () {
    $other = User::factory()->create(['username' => 'someone-else']);
    Passport::actingAs(User::factory()->create());

    $this->patchJson('/api/users/'.$other->id, ['username' => 'impostor'])->assertForbidden();

    expect($other->fresh()->username)->toBe('someone-else');
});

test('an invalid payload is rejected with the field that fails', function () {
    $taken = User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/users/'.$user->id, ['username' => $taken->username])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username']);

    $this->patchJson('/api/users/'.$user->id, ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->patchJson('/api/users/'.$user->id, ['password' => 'short'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('a common password sent to the api is rejected', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/users/'.$user->id, ['password' => 'Motdepasse1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('a patch carrying nothing is not an update', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/users/'.$user->id, [])->assertStatus(422);
});

test('an unknown profile is not found', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/users/999999')->assertNotFound();
    $this->patchJson('/api/users/999999', ['username' => 'ghost'])->assertNotFound();
});
