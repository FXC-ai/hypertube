<?php

use App\Data\MediaInfo;
use App\Exceptions\MediaConversionException;
use App\Services\Media\MediaProbe;
use App\Services\Media\MovieInput;
use Tests\TestCase;

uses(TestCase::class);

test('the media probe rejects a missing source before launching ffprobe', function () {
    expect(fn (): MediaInfo => (new MediaProbe)->probe(new MovieInput('missing-source.mkv', isStream: false)))
        ->toThrow(MediaConversionException::class, 'Source file not found.');
});

test('the media probe returns the media duration', function () {
    $mediaInfo = (new MediaProbe)->probe(new MovieInput(
        location: base_path('tests/Fixtures/test2.mkv'),
        isStream: false,
    ));

    expect($mediaInfo->durationSeconds)->toBeGreaterThan(0.0);
});
