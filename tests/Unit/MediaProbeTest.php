<?php

use App\Data\MediaInfo;
use App\Exceptions\MediaConversionException;
use App\Services\Media\MediaProbe;
use App\Services\Media\MovieInput;

test('the media probe rejects a missing source before launching ffprobe', function () {
    expect(fn (): MediaInfo => (new MediaProbe)->probe(new MovieInput('missing-source.mkv', isStream: false)))
        ->toThrow(MediaConversionException::class, 'Source file not found.');
});
