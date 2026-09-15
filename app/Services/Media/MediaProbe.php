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
    public function probe(string $inputPath): MediaInfo
    {
        if (! is_file($inputPath)) {
            throw new MediaConversionException('Source file not found.');
        }

        $process = new Process([
            (string) config('media.ffprobe_binary'),
            '-v',
            'error',
            '-show_streams',
            '-of',
            'json',
            $inputPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new MediaConversionException('FFprobe failed : ' . $this->errorSummary($process), previous: new ProcessFailedException($process));
        }

        try {
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MediaConversionException('FFprobe a returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || ! is_array($decoded['streams'] ?? null)) {
            throw new MediaConversionException('FFprobe response has no pist list.');
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

        return new MediaInfo($streams);
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
