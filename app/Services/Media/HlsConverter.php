<?php

namespace App\Services\Media;

use App\Exceptions\MediaConversionException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

final class HlsConverter
{
    /**
     *@paramlist<string> $command
     *@paramcallable(): void $onProgress
     */
    public function convert(array $command, callable $onProgress): void
    {
        Log::channel("my_debug")->debug("convert", ["called"]);

        $process = new Process($command);
        $process->setTimeout(null);
        $lastCheck = 0.0;

        $exitCode = $process->run(
            function () use ($onProgress, &$lastCheck): void {
                $now = microtime(true);

                if ($now - $lastCheck >= 0.5) {
                    $onProgress();
                    $lastCheck = $now;
                }
            }
        );

        $onProgress();

        if ($exitCode !== 0) {
            $error = trim($process->getErrorOutput());

            throw new MediaConversionException(
                'FFmpeg a échoué : ' . mb_substr($error !== '' ? $error : 'erreur inconnue', 0, 2000),
            );
        }
    }
}
