<?php

namespace App\Services\Media;

use App\Data\MediaInfo;
use App\Data\MediaStream;
use App\Exceptions\MediaConversionException;
use JsonException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class MediaProbe
{
    public function probe(MovieInput $movieInput): MediaInfo
    {
        if (! $movieInput->isStream && ! is_file($movieInput->location)) {
            throw new MediaConversionException('Source file not found.');
        }

        $process = new Process([
            (string) config('media.ffprobe_binary'),
            '-v',
            'error',
            ...$movieInput->inputOptions(),
            '-show_streams',
            '-show_format',
            '-of',
            'json',
            $movieInput->location,
        ]);

        $process->setTimeout($movieInput->isStream ? (int) config('media.stream.probe_timeout_s') : 60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new MediaConversionException('FFprobe failed : '.$this->errorSummary($process), previous: new ProcessFailedException($process));
        }

        try {
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MediaConversionException('FFprobe a returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || ! is_array($decoded['streams'] ?? null)) {
            throw new MediaConversionException('FFprobe response has no pist list.');
        }

        $rawDuration = $decoded['format']['duration'] ?? null;

        if (! is_numeric($rawDuration)) {
            throw new MediaConversionException(
                'FFprobe did not return a usable media duration.',
            );
        }

        $durationSeconds = (float) $rawDuration;

        if (! is_finite($durationSeconds) || $durationSeconds <= 0) {
            throw new MediaConversionException(
                'FFprobe returned an invalid media duration.',
            );
        }

        $streams = [];

        foreach ($decoded['streams'] as $rawStream) {
            if (! is_array($rawStream)) {
                continue;
            }

            $tags = is_array($rawStream['tags'] ?? null) ? $rawStream['tags'] : [];
            $disposition = is_array($rawStream['disposition'] ?? null) ? $rawStream['disposition'] : [];

            $streams[] = new MediaStream(
                index: (int) ($rawStream['index'] ?? -1),
                type: (string) ($rawStream['codec_type'] ?? ''),
                codec: strtolower((string) ($rawStream['codec_name'] ?? '')),
                language: $this->normalizeLanguage(is_string($tags['language'] ?? null) ? $tags['language'] : null),
                title: is_string($tags['title'] ?? null) ? $tags['title'] : '',
                disposition: $disposition,
            );
        }

        return new MediaInfo(streams: $streams, durationSeconds: $durationSeconds);
    }

    private function normalizeLanguage(?string $language): string
    {
        return match (strtolower(trim($language ?? 'und'))) {
            'fra', 'fre', 'fr', 'french' => 'fra',
            'eng', 'en', 'english' => 'eng',
            'deu', 'ger', 'de', 'german' => 'deu',
            'ita', 'it', 'italian' => 'ita',
            default => 'und',
        };
    }

    private function errorSummary(Process $process): string
    {
        $message = trim($process->getErrorOutput());

        return mb_substr($message !== '' ? $message : 'erreur inconnue', 0, 1000);
    }
}
