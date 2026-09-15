<?php

namespace App\Services\Media;

use App\Data\SelectedTracks;
use Illuminate\Support\Facades\Log;

final class HlsCommandBuilder
{
    /**@return list<string> */
    public function build(string $inputPath, string $outputDirectory, SelectedTracks $tracks): array
    {
        $segmentDuration = (int) config('media.hls.segment_duration', 6);
        $separator = DIRECTORY_SEPARATOR;

        $arguments = [
            (string) config('media.ffmpeg_binary'),
            '-hide_banner',
            '-y',
            '-progress',
            'pipe:1',
            '-nostats',
            '-i',
            $inputPath,
            '-map',
            "0:{$tracks->video->index}",
        ];

        if ($tracks->audio !== null) {
            array_push($arguments, '-map', "0:{$tracks->audio->index}");
        }

        array_push(
            $arguments,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '23',
            '-pix_fmt',
            'yuv420p',
            '-sc_threshold',
            '0',
            '-force_key_frames',
            "expr:gte(t,n_forced*{$segmentDuration})",
        );

        if ($tracks->audio !== null) {
            array_push($arguments, '-c:a', 'aac', '-b:a', '128k', '-ac', '2');
        }

        array_push(
            $arguments,
            '-f',
            'hls',
            '-hls_time',
            (string) $segmentDuration,
            '-hls_playlist_type',
            'event',
            '-hls_list_size',
            '0',
            '-hls_flags',
            'independent_segments+temp_file',
            '-hls_segment_filename',
            $outputDirectory . $separator . 'video_%05d.ts',
            $outputDirectory . $separator . 'video.m3u8',
        );

        foreach ($tracks->subtitles as $position => $subtitle) {
            $prefix = sprintf('subtitle_%02d_%s', $position, $subtitle->language);

            array_push(
                $arguments,
                '-map',
                "0:{$subtitle->index}",
                '-c:s',
                'webvtt',
                '-f',
                'segment',
                '-segment_time',
                (string) $segmentDuration,
                '-segment_list',
                $outputDirectory . $separator . "{$prefix}.m3u8",
                '-segment_list_type',
                'm3u8',
                '-segment_list_flags',
                '+live',
                '-segment_list_size',
                '0',
                $outputDirectory . $separator . "{$prefix}_%05d.vtt",
            );
        }

        Log::channel("my_debug")->debug("HlsCommandBuilder = ", [$arguments]);

        return $arguments;
    }
}
