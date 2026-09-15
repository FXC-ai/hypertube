<?php

namespace App\Services\Media;

use App\Data\MediaInfo;
use App\Data\MediaStream;
use App\Data\SelectedTracks;
use App\Exceptions\MediaConversionException;



final class TrackSelector
{
    public function select(MediaInfo $media): SelectedTracks
    {
        $videos = $media->videos();

        if ($videos === []) {
            throw new MediaConversionException('No video track avaible.');
        }

        return new SelectedTracks(
            video: $videos[0],
            audio: $this->selectAudio($media->audios()),
            subtitles: array_values(array_filter(
                $media->subtitles(),
                fn(MediaStream $stream): bool => in_array(
                    $stream->language,
                    ['fra', 'eng', 'deu', 'ita'],
                    true,
                ),
            )),
        );
    }

    /**@paramlist<MediaStream> $audios */
    private function selectAudio(array $audios): ?MediaStream
    {
        foreach (['eng', 'fra', 'deu', 'ita'] as $preferredLanguage) {
            foreach ($audios as $audio) {
                if ($audio->language === $preferredLanguage) {
                    return $audio;
                }
            }
        }

        return $audios[0] ?? null;
    }
}
