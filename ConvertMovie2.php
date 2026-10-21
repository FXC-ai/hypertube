<?php

namespace App\Jobs;

use App\Data\SelectedTracks;
use App\Data\SubtitleTrack;
use App\Enums\ConversionStatus;
use App\Models\Movie;
use App\Services\Media\ExternalSubtitlePreparer;
use App\Services\Media\HlsCommandBuilder;
use App\Services\Media\HlsCompletionValidator;
use App\Services\Media\HlsConverter;
use App\Services\Media\HlsMasterPlaylistBuilder;
use App\Services\Media\HlsReadinessChecker;
use App\Services\Media\MediaProbe;
use App\Services\Media\MovieInput;
use App\Services\Media\MovieInputResolver;
use App\Services\Media\TrackSelector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ConvertMovie implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $movieId)
    {
        $this->onConnection('conversions');
        $this->onQueue('conversions');
    }

    public function handle(
        MediaProbe $probe,
        TrackSelector $trackSelector,
        HlsCommandBuilder $hlsCommandBuilder,
        HlsReadinessChecker $hlsReadinessChecker,
        HlsCompletionValidator $hlsCompletionValidator,
        HlsMasterPlaylistBuilder $hlsMasterPlaylistBuilder,
        HlsConverter $hlsConverter,
        MovieInputResolver $movieInputResolver,
        ExternalSubtitlePreparer $externalSubtitlePreparer,
    ): void {
        $context = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'movieId' => $this->movieId,
        ];

        Log::channel('my_debug')->debug(
            $context['class']."\n".json_encode(
                [
                    'method' => $context['method'],
                    'explication' => 'Le job de conversion du film démarre.',
                    'Informations' => [
                        'movieId' => $context['movieId'],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            )."\n",
        );

        $attempt = (string) Str::uuid();
        $startedAt = now();

        $claimed = Movie::query()
            ->whereKey($this->movieId)
            ->where('conversion_status', ConversionStatus::Queued->value)
            ->update([
                'conversion_status' => ConversionStatus::Converting->value,
                'conversion_attempt' => $attempt,
                'conversion_error' => null,
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => null,
                'conversion_completed_at' => null,
            ]);

        if ($claimed !== 1) {
            Log::channel('my_debug')->debug(
                $context['class']."\n".json_encode(
                    [
                        'method' => $context['method'],
                        'explication' => 'La conversion ne démarre pas car le film n’est plus en attente de conversion.',
                        'Informations' => [
                            'movieId' => $context['movieId'],
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            return;
        }

        $movie = Movie::query()->findOrFail($this->movieId);

        Log::channel('my_debug')->info(
            $context['class']."\n".json_encode(
                [
                    'method' => $context['method'],
                    'explication' => 'Le film à convertir a été chargé.',
                    'Informations' => [
                        'movieId' => $context['movieId'],
                        'filename' => $movie->filename,
                        'attempt' => $attempt,
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            )."\n",
        );

        try {
            if (! self::isInsideMovieDirectory($movie->filename)) {
                throw new \RuntimeException('Invalid file name.');
            }

            $movieInput = $movieInputResolver->resolve($movie);
            $outputDirectory = Storage::disk('public')->path("movies/{$movie->id}/hls/{$attempt}");

            if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true)) {
                throw new \RuntimeException('Can not create hls directory.');
            }

            $sourceMediaInfo = $probe->probe($movieInput);
            $tracksSelected = $trackSelector->select($sourceMediaInfo);
            $ffmpegInputs = [$movieInput];
            $subtitleTracks = $tracksSelected->subtitles;

            foreach ($movie->external_subtitles ?? [] as $position => $subtitle) {
                $source = $movieInputResolver->resolveExternalSubtitle($movie, $subtitle);
                $preparedPath = $outputDirectory.DIRECTORY_SEPARATOR.sprintf(
                    'external_%02d.vtt',
                    $position,
                );

                if (! $externalSubtitlePreparer->prepare(
                    $source,
                    $preparedPath,
                    $sourceMediaInfo->durationSeconds,
                )) {
                    Log::channel('my_debug')->warning(
                        "Un sous-titre externe invalide a été ignoré.\n".json_encode(
                            [
                                ...$context,
                                'attempt' => $attempt,
                                'file_name' => $subtitle['file_name'],
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                        )."\n",
                    );

                    continue;
                }

                $inputIndex = count($ffmpegInputs);
                $ffmpegInputs[] = new MovieInput(
                    location: $preparedPath,
                    isStream: false,
                );
                $subtitleTracks[] = new SubtitleTrack(
                    inputIndex: $inputIndex,
                    streamIndex: 0,
                    language: $subtitle['language'],
                    title: $subtitle['file_name'],
                    origin: 'external',
                );
            }

            $tracksSelected = new SelectedTracks(
                video: $tracksSelected->video,
                audio: $tracksSelected->audio,
                subtitles: $subtitleTracks,
            );

            Log::channel('my_debug')->info(
                $context['class']."\n".json_encode(
                    [
                        'method' => $context['method'],
                        'explication' => 'Les pistes du film ont été analysées et sélectionnées.',
                        'Informations' => [
                            'movieId' => $context['movieId'],
                            'attempt' => $attempt,
                            'tracksSelected' => $tracksSelected,
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            $published = false;

            $publishWhenReady = function () use (
                $movie,
                $attempt,
                $outputDirectory,
                $tracksSelected,
                $hlsReadinessChecker,
                $hlsMasterPlaylistBuilder,
                &$published,
            ): void {
                if ($published || ! $hlsReadinessChecker->isReady($outputDirectory, $tracksSelected)) {
                    return;
                }

                $hlsMasterPlaylistBuilder->publish($outputDirectory, $tracksSelected);

                $updated = Movie::query()
                    ->whereKey($movie->id)
                    ->where('conversion_attempt', $attempt)
                    ->where('conversion_status', ConversionStatus::Converting->value)
                    ->update([
                        'conversion_status' => ConversionStatus::Playable->value,
                        'conversion_playable_at' => now(),
                    ]);

                $published = $updated === 1;

                if ($published) {
                    Log::channel('my_debug')->info(
                        "Le film est désormais disponible à la lecture, mais sa conversion continue.\n".json_encode(
                            [
                                'class' => self::class,
                                'method' => 'handle',
                                'movie_id' => $movie->id,
                                'attempt' => $attempt,
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                        )."\n",
                    );
                }
            };

            $hlsConverter->convert(
                $hlsCommandBuilder->build(
                    $ffmpegInputs,
                    $outputDirectory,
                    $tracksSelected,
                ),
                $publishWhenReady,
            );

            $publishWhenReady();

            if (! $published) {
                throw new \RuntimeException('FFmpeg finished without producing avaible hls segment.');
            }

            $playlistPath = $outputDirectory.DIRECTORY_SEPARATOR.'video.m3u8';

            $hlsMediaInfo = $probe->probe(new MovieInput(
                location: $playlistPath,
                isStream: false,
            ));

            $hlsCompletionValidator->assertComplete(
                playlistPath: $playlistPath,
                sourceDurationSeconds: $sourceMediaInfo->durationSeconds,
                hlsDurationSeconds: $hlsMediaInfo->durationSeconds,
            );

            Movie::query()
                ->whereKey($movie->id)
                ->where('conversion_attempt', $attempt)
                ->where('conversion_status', ConversionStatus::Playable->value)
                ->update([
                    'conversion_status' => ConversionStatus::Converted->value,
                    'conversion_completed_at' => now(),
                ]);

            Log::channel('my_debug')->info(
                $context['class']."\n".json_encode(
                    [
                        'method' => $context['method'],
                        'explication' => 'La conversion du film est terminée.',
                        'Informations' => [
                            'movieId' => $context['movieId'],
                            'attempt' => $attempt,
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );
        } catch (Throwable $exception) {
            Log::channel('my_debug')->error(
                $context['class']."\n".json_encode(
                    [
                        'method' => $context['method'],
                        'explication' => 'La conversion HLS du film a échoué.',
                        'Informations' => [
                            'movieId' => $context['movieId'],
                            'attempt' => $attempt,
                            'exception' => [
                                'type' => $exception::class,
                                'message' => $exception->getMessage(),
                                'file' => $exception->getFile(),
                                'line' => $exception->getLine(),
                            ],
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            Movie::query()
                ->whereKey($movie->id)
                ->where('conversion_attempt', $attempt)
                ->update([
                    'conversion_status' => ConversionStatus::Failed->value,
                    'conversion_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);

            throw $exception;
        }
    }

    /**
     * Un torrent peut stocker le film dans un sous-dossier (« M(1931)/M.1931.mp4 ») : les chemins relatifs sont
     * autorisés, mais rien qui puisse sortir du dossier movies/{id}.
     */
    private static function isInsideMovieDirectory(string $filename): bool
    {
        if ($filename === '' || str_contains($filename, '\\') || str_starts_with($filename, '/')) {
            return false;
        }

        foreach (explode('/', $filename) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }

        return true;
    }
}
