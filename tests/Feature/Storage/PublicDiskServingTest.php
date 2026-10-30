<?php

use Illuminate\Support\Facades\Storage;

/*
 * Avatars, subtitles and HLS playlists are all linked as `/storage/...`. Nginx serves
 * them in Docker; the framework route serves them when the app runs without a front
 * server, which is how `composer run dev` starts the site.
 */
test('a file of the public disk is reachable through its link', function () {
    Storage::fake('public');
    Storage::disk('public')->put('movies/7/hls/attempt/index.m3u8', "#EXTM3U\n");

    $this->get('/storage/movies/7/hls/attempt/index.m3u8')->assertOk();
});

test('a picture uploaded to the public disk is reachable through its link', function () {
    Storage::fake('public');
    Storage::disk('public')->put('avatars/uuid.png', 'picture');

    $this->get('/storage/avatars/uuid.png')->assertOk();
});

test('a file of the private disk is never served through the public link', function () {
    Storage::fake('local');
    Storage::disk('local')->put('private-notes.txt', 'hidden');

    expect($this->get('/storage/private-notes.txt')->status())->toBeIn([403, 404]);
});
