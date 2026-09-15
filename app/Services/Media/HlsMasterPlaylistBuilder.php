<?php

namespace App\Services\Media;

use App\Data\MediaStream;
use App\Data\SelectedTracks;
use App\Exceptions\MediaConversionException;
use Illuminate\Support\Facades\Log;



final class HlsMasterPlaylistBuilder
{
    public function publish(string $directory, SelectedTracks $tracks): void
    {

        Log::channel("my_debug")->debug("HlsPlaylistBuilder", ["publish is called"]);

        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        foreach ($tracks->subtitles as $position => $subtitle) {
            $prefix = sprintf('subtitle_%02d_%s', $position, $subtitle->language);
            $name = $this->attribute($this->displayName($subtitle, $position));
            $language = $this->attribute($subtitle->hlsLanguage());

            $lines[] = sprintf(
                '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="%s",LANGUAGE="%s",AUTOSELECT=YES,DEFAULT=NO,URI="%s.m3u8"',
                $name,
                $language,
                $prefix,
            );
        }

        $stream = '#EXT-X-STREAM-INF:BANDWIDTH=' . (int) config('media.hls.bandwidth', 1_700_000);

        if ($tracks->subtitles !== []) {
            $stream .= ',SUBTITLES="subtitles"';
        }

        $lines[] = $stream;
        $lines[] = 'video.m3u8';

        $temporaryPath = $directory . DIRECTORY_SEPARATOR . 'index.m3u8.tmp';
        $finalPath = $directory . DIRECTORY_SEPARATOR . 'index.m3u8';
        $contents = implode("\n", $lines) . "\n";

        Log::channel("my_debug")->debug("HlsPlaylistBuilder", ["manifest" => $contents]);


        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new MediaConversionException('Impossible d’écrire le manifeste HLS temporaire.');
        }

        if (! rename($temporaryPath, $finalPath)) {
            @unlink($temporaryPath);

            throw new MediaConversionException('Impossible de publier le manifeste HLS.');
        }
    }

    private function displayName(MediaStream $subtitle, int $position): string
    {
        if (trim($subtitle->title) !== '') {
            return trim($subtitle->title) . ' ' . ($position + 1);
        }

        return match ($subtitle->language) {
            'fra' => 'Français ' . ($position + 1),
            'eng' => 'English ' . ($position + 1),
            'deu' => 'Deutsch ' . ($position + 1),
            'ita' => 'Italiano ' . ($position + 1),
            default => 'Subtitle ' . ($position + 1),
        };
    }

    private function attribute(string $value): string
    {
        $singleLine = str_replace(["\r", "\n"], ' ', $value);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $singleLine);
    }
}
