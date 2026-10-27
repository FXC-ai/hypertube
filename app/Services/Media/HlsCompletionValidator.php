<?php

namespace App\Services\Media;

use App\Exceptions\MediaConversionException;
use Illuminate\Support\Facades\Log;

final class HlsCompletionValidator
{
    public function assertComplete(
        string $playlistPath,
        float $sourceDurationSeconds,
        float $hlsDurationSeconds,
    ): void {

        $context = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'sourceDurationSeconds' => $sourceDurationSeconds,
            'hlsDurationSeconds' => $hlsDurationSeconds
        ];
        Log::channel('my_debug')->info(
            "Vérification de la conversion \n" . json_encode(
                [
                    ...$context,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ) . "\n",
        );
        if (! is_finite($sourceDurationSeconds) || $sourceDurationSeconds <= 0) {
            Log::channel('my_debug')->error(
                "La durée de la source fournie par ffbrobe est invalide.\n" . json_encode(
                    [
                        ...$context,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
            throw new MediaConversionException('The source duration is invalid.');
        }

        if (! is_finite($hlsDurationSeconds) || $hlsDurationSeconds <= 0) {
            Log::channel('my_debug')->error(
                "La durée des hls post conversion est invalide.\n" . json_encode(
                    [
                        ...$context,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
            throw new MediaConversionException('The HLS duration is invalid.');
        }

        $contents = is_file($playlistPath) ? file_get_contents($playlistPath) : false;

        if ($contents === false) {
            Log::channel('my_debug')->error(
                "video.m3u8 est manquant ou son contenu est invalide.\n" . json_encode(
                    [
                        ...$context,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
            throw new MediaConversionException(
                'The final HLS video playlist is missing or unreadable.',
            );
        }

        $lines = preg_split('/\R/', trim($contents));

        if ($lines === false || end($lines) !== '#EXT-X-ENDLIST') {
            Log::channel('my_debug')->error(
                "La playlist video.m3u8 ne se termine pas par EXT-X-ENDLIST\n" . json_encode(
                    [
                        ...$context,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
            throw new MediaConversionException(
                'The final HLS video playlist has no final EXT-X-ENDLIST marker.',
            );
        }

        $segmentDurationSeconds = (float) config('media.hls.segment_duration', 6);
        $minimumRatio = (float) config('media.hls.completion_minimum_ratio', 0.99);
        $toleranceSegments = (int) config('media.hls.completion_tolerance_segments', 2);

        $minimumAcceptedDuration = max(
            $sourceDurationSeconds * $minimumRatio,
            $sourceDurationSeconds - ($segmentDurationSeconds * $toleranceSegments),
        );

        if ($hlsDurationSeconds < $minimumAcceptedDuration) {
            Log::channel('my_debug')->error(
                "La durée totals des segments hls ne correspond pas à la durée attendue.\n" . json_encode(
                    [
                        ...$context,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
            throw new MediaConversionException(sprintf(
                'The HLS output is truncated: %.3f seconds produced for a %.3f-second source; at least %.3f seconds were required.',
                $hlsDurationSeconds,
                $sourceDurationSeconds,
                $minimumAcceptedDuration,
            ));
        }

        Log::channel('my_debug')->info(
            "Le contrôle des fichiers converties est passé avec succés\n" . json_encode(
                [
                    ...$context,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ) . "\n",
        );
    }
}
