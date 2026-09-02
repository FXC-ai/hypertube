<?php

use App\Models\Movie;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('an authenticated user can request an entire movie', function () {
    Storage::fake('public');

    $contents = '0123456789';

    Storage::disk('public')->put('movies/test.mp4', $contents);

    $movie = Movie::factory()->create([
        'filepath' => 'movies/test.mp4',
        'filename' => 'test.mp4',
    ]);

    $response = $this
        ->actingAs(User::factory()->create())
        ->get(route('movies.watch', $movie));

    $response
        ->assertOk()
        ->assertHeader('content-type', 'video/mp4')
        ->assertHeader('content-length', (string) strlen($contents))
        ->assertHeader('accept-ranges', 'bytes');
});

test('an authenticated user can request a byte range of a movie', function () {
    Storage::fake('public');

    $contents = '0123456789';

    Storage::disk('public')->put('movies/test.mp4', $contents);

    $movie = Movie::factory()->create([
        'filepath' => 'movies/test.mp4',
        'filename' => 'test.mp4',
    ]);

    $response = $this
        ->actingAs(User::factory()->create())
        ->withHeader('Range', 'bytes=2-5')
        ->get(route('movies.watch', $movie));

    $response
        ->assertStatus(206)
        ->assertHeader('accept-ranges', 'bytes')
        ->assertHeader('content-range', 'bytes 2-5/10')
        ->assertHeader('content-length', '4');
});
