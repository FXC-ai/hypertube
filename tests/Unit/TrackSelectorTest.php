<?php

use App\Data\MediaInfo;
use App\Data\MediaStream;
use App\Data\SelectedTracks;
use App\Services\Media\TrackSelector;

use App\Exceptions\MediaConversionException;


function selectedTracksFixture(): SelectedTracks
{
    return new SelectedTracks(
        video: new MediaStream(0, 'video', 'h264', 'und', '', []),
        audio: new MediaStream(1, 'audio', 'aac', 'eng', '', []),
        subtitles: [
            new MediaStream(2, 'subtitle', 'subrip', 'eng', '', []),
            new MediaStream(3, 'subtitle', 'webvtt', 'fra', '', []),
        ],
    );
}

function mediaInfoFixture(): MediaInfo
{
    return new MediaInfo([
        new MediaStream(0, 'video', 'h264', 'und', '', []),
        new MediaStream(1, 'audio', 'aac', 'eng', '', []),
        new MediaStream(2, 'subtitle', 'subrip', 'eng', '', []),
        new MediaStream(3, 'subtitle', 'subrip', 'fra', '', []),
        new MediaStream(4, 'subtitle', 'ass', 'deu', '', []),
        new MediaStream(5, 'subtitle', 'mov_text', 'ita', '', []),
        new MediaStream(6, 'subtitle', 'subrip', 'spa', '', []),
    ]);
}

test('the track selector keeps the video, preferred audio, and supported subtitles', function () {
    $tracks = (new TrackSelector)->select(mediaInfoFixture());

    expect($tracks->video->index)->toBe(0)
        ->and($tracks->audio?->index)->toBe(1)
        ->and(array_map(
            fn(MediaStream $subtitle): string => $subtitle->language,
            $tracks->subtitles,
        ))->toBe(['eng', 'fra', 'deu', 'ita']);
});


test('the track selector keeps the video, preferred audio, and supported subtitles', function () {
    $tracks = (new TrackSelector)->select(mediaInfoFixture());

    expect($tracks->video->index)->toBe(0)
        ->and($tracks->audio?->index)->toBe(1)
        ->and(array_map(
            fn(MediaStream $subtitle): string => $subtitle->language,
            $tracks->subtitles,
        ))->toBe(['eng', 'fra', 'deu', 'ita']);
});

test('the track selector rejects media without a video track', function () {
    $media = new MediaInfo([new MediaStream(1, 'audio', 'aac', 'eng', '', [])]);

    expect(fn(): SelectedTracks => (new TrackSelector)->select($media))->toThrow(MediaConversionException::class, 'No video track avaible.');
});
