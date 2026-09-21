<?php

use App\Enums\ConversionStatus;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('returns complete HLS playlists without range responses', function (): void {
    Storage::fake('public');

    $attempt = '11111111-1111-4111-8111-111111111111';
    $movie = Movie::factory()->withConversionStatus(ConversionStatus::Playable)->create([
        'conversion_attempt' => $attempt,
    ]);

    Storage::disk('public')->put(
        "movies/{$movie->id}/hls/{$attempt}/index.m3u8",
        "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1700000\nvideo.m3u8\n",
    );
    Storage::disk('public')->put(
        "movies/{$movie->id}/hls/{$attempt}/video.m3u8",
        "#EXTM3U\n#EXT-X-PLAYLIST-TYPE:EVENT\n#EXTINF:6,\nvideo_00000.ts\n",
    );

    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get(route('movies.hls.manifest', [$movie, $attempt]), ['Range' => 'bytes=0-20'])
        ->assertOk()
        ->assertHeader('Accept-Ranges', 'none')
        ->assertSee('#EXTM3U');

    $this->actingAs($user)
        ->get(route('movies.hls.segment', [$movie, $attempt, 'video.m3u8']), ['Range' => 'bytes=0-20'])
        ->assertOk()
        ->assertHeader('Accept-Ranges', 'none')
        ->assertSee('#EXT-X-PLAYLIST-TYPE:EVENT');
});
