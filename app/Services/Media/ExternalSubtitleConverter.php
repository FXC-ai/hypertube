<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ExternalSubtitleConverter
{
    public function convert(string $sourcePath, string $outputPath): bool
    {
        $context = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'source' => $sourcePath,
        ];
        $process = new Process([
            'ffmpeg',
            '-hide_banner',
            '-y',
            '-xerror',
            '-i',
            $sourcePath,
            '-map',
            '0:0',
            '-c:s',
            'webvtt',
            $outputPath,
        ]);
        $process->setTimeout(60);

        try {
            $process->mustRun();
            Log::channel('my_debug')->info('La conversion des sous-titre externes a été faite', $context);
        } catch (ProcessFailedException|ProcessTimedOutException) {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
            Log::channel('my_debug')->error('La conversion des sous-titre externes a échouée', $context);

            return false;
        }

        return is_file($outputPath) && filesize($outputPath) > 0;
    }
}
