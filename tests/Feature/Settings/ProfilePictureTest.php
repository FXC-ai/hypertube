<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('an authenticated user can add a profile picture in his profile', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $avatar = new UploadedFile(
        base_path('tests\Fixtures\avatar.jpeg'),
        'avatar.jpg',
        'image/jpeg',
        null,
        true,
    );

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => $avatar,
        ]);

    $response->assertOk();

    $profilepicturePath = $response->json('path');

    Storage::disk('public')->assertExists($profilepicturePath);

    expect($user->refresh()->profilepicture)->toBe($profilepicturePath);
});

test('an authenticated user can update their profile picture', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $avatar = new UploadedFile(
        base_path('tests\Fixtures\avatar.jpeg'),
        'avatar.jpg',
        'image/jpeg',
        null,
        true,
    );

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => $avatar,
        ]);

    $response->assertOk();

    $profilepicturePath = $response->json('path');

    Storage::disk('public')->assertExists($profilepicturePath);

    expect($user->refresh()->profilepicture)->toBe($profilepicturePath);

    $avatar2 = new UploadedFile(
        base_path('tests\Fixtures\avatar2.jpeg'),
        'avatar.jpg',
        'image/jpeg',
        null,
        true,
    );

    $response2 = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => $avatar2,
        ]);

    $response2->assertOk();

    $profilepicturePath2 = $response2->json('path');

    Storage::disk('public')->assertExists($profilepicturePath2);

    Storage::disk('public')->assertMissing($profilepicturePath);

    expect($user->refresh()->profilepicture)->toBe($profilepicturePath2);
});

test('an unauthenticated user cannot update a profile picture', function () {
    Storage::fake('public');

    $response = $this->patch(route('update.avatar'));

    $response->assertRedirect(route('login'));

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a profile picture is required', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'));

    $response->assertSessionHasErrors('profilepicture');

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a profile picture must be an image', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => UploadedFile::fake()->createWithContent('avatar.txt', 'not an image'),
        ]);

    $response->assertSessionHasErrors('profilepicture');

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a profile picture may not be larger than two megabytes', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => UploadedFile::fake()->image('avatar.jpg')->size(2049),
        ]);

    $response->assertSessionHasErrors('profilepicture');

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a profile picture may not be wider than 2000 pixels', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => UploadedFile::fake()->image('avatar.jpg', 2001, 1000),
        ]);

    $response->assertSessionHasErrors('profilepicture');

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a profile picture may not be taller than 2000 pixels', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => UploadedFile::fake()->image('avatar.jpg', 1000, 2001),
        ]);

    $response->assertSessionHasErrors('profilepicture');

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('the previous profile picture is preserved when validation fails', function () {
    Storage::fake('public');

    $previousProfilepicture = 'avatars/previous-avatar.jpg';
    Storage::disk('public')->put($previousProfilepicture, 'previous avatar');

    $user = User::factory()->create([
        'profilepicture' => $previousProfilepicture,
    ]);

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => UploadedFile::fake()->createWithContent('avatar.txt', 'not an image'),
        ]);

    $response->assertSessionHasErrors('profilepicture');

    Storage::disk('public')->assertExists($previousProfilepicture);

    expect($user->refresh()->profilepicture)->toBe($previousProfilepicture);
});

test('profile pictures are stored and deleted on the public disk', function () {
    Storage::fake('local');
    Storage::fake('public');

    $previousProfilepicture = 'avatars/previous-avatar.jpg';
    Storage::disk('local')->put($previousProfilepicture, 'local avatar');
    Storage::disk('public')->put($previousProfilepicture, 'public avatar');

    $user = User::factory()->create([
        'profilepicture' => $previousProfilepicture,
    ]);

    $avatar = new UploadedFile(
        base_path('tests\Fixtures\avatar.jpeg'),
        'avatar.jpg',
        'image/jpeg',
        null,
        true,
    );

    $response = $this
        ->actingAs($user)
        ->patch(route('update.avatar'), [
            'profilepicture' => $avatar,
        ]);

    $response->assertOk();

    $profilepicturePath = $response->json('path');

    Storage::disk('public')->assertExists($profilepicturePath);
    Storage::disk('public')->assertMissing($previousProfilepicture);
    Storage::disk('local')->assertMissing($profilepicturePath);
    Storage::disk('local')->assertExists($previousProfilepicture);

    expect($user->refresh()->profilepicture)->toBe($profilepicturePath);
});
