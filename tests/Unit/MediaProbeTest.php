<?php

use App\Data\MediaInfo;
use App\Exceptions\MediaConversionException;
use App\Services\Media\MediaProbe;



test('the media probe rejects a missing source before launching ffprobe', function () {
    expect(fn(): MediaInfo => (new MediaProbe)->probe('missing-source.mkv'))
        ->toThrow(MediaConversionException::class, 'Source file not found.');
});
