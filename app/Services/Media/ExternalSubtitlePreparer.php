<?php

namespace App\Services\Media;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final class ExternalSubtitlePreparer
{

    public function prepare(
        MovieInput $input,
        string $outputPath,
        float $videoDurationSeconds,
    ): bool {
        $process = new Process([
            (string) config('media.ffmpeg_binary'),
            '-hide_banner',
            '-y',
            ...$input->inputOptions(),
            '-i',
            $input->location,
            '-map',
            '0:0',
            '-c:s',
            'webvtt',
            '-t',
            (string) $videoDurationSeconds,
            $outputPath,
        ]);

        $process->setTimeout($input->isStream ? 30 : 60);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return false;
        } catch (Throwable) {
            return false;
        }

        return $process->isSuccessful()
            && is_file($outputPath)
            && filesize($outputPath) > 0;
    }
}
