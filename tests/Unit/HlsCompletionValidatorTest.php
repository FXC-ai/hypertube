<?php

use App\Exceptions\MediaConversionException;
use App\Services\Media\HlsCompletionValidator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

function writeFinalVideoPlaylist(string $contents): string
{
    Storage::disk('public')->put('validation/video.m3u8', $contents);

    return Storage::disk('public')->path('validation/video.m3u8');
}

beforeEach(function () {
    Storage::fake('public');
});

test('a complete hls duration is accepted', function () {
    $playlistPath = writeFinalVideoPlaylist(<<<'M3U8'
#EXTM3U
#EXTINF:6.000000,
video_00000.ts
#EXT-X-ENDLIST
M3U8);

    (new HlsCompletionValidator)->assertComplete(
        playlistPath: $playlistPath,
        sourceDurationSeconds: 100.0,
        hlsDurationSeconds: 99.5,
    );
})->throwsNoExceptions();

test('a severely truncated hls duration is rejected', function () {
    $playlistPath = writeFinalVideoPlaylist(<<<'M3U8'
#EXTM3U
#EXTINF:0.250267,
video_00000.ts
#EXT-X-ENDLIST
M3U8);

    expect(fn () => (new HlsCompletionValidator)->assertComplete(
        playlistPath: $playlistPath,
        sourceDurationSeconds: 7814.634,
        hlsDurationSeconds: 0.250267,
    ))->toThrow(
        MediaConversionException::class,
        'The HLS output is truncated',
    );
});

test('a playlist without an end marker is rejected', function () {
    $playlistPath = writeFinalVideoPlaylist(<<<'M3U8'
#EXTM3U
#EXTINF:6.000000,
video_00000.ts
M3U8);

    expect(fn () => (new HlsCompletionValidator)->assertComplete(
        playlistPath: $playlistPath,
        sourceDurationSeconds: 6.0,
        hlsDurationSeconds: 6.0,
    ))->toThrow(
        MediaConversionException::class,
        'The final HLS video playlist has no final EXT-X-ENDLIST marker.',
    );
});

test('a missing final playlist is rejected', function () {
    expect(fn () => (new HlsCompletionValidator)->assertComplete(
        playlistPath: Storage::disk('public')->path('missing/video.m3u8'),
        sourceDurationSeconds: 60.0,
        hlsDurationSeconds: 60.0,
    ))->toThrow(
        MediaConversionException::class,
        'The final HLS video playlist is missing or unreadable.',
    );
});
