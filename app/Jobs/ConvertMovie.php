<?php

namespace App\Jobs;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use App\Services\Media\HlsCommandBuilder;
use App\Services\Media\HlsConverter;
use App\Services\Media\HlsMasterPlaylistBuilder;
use App\Services\Media\HlsReadinessChecker;
use App\Services\Media\MediaProbe;
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

    public int $timeout = 0; // Il n'y a pas de timeout sur ce job

    public int $tries = 1; // ConvertMovie n'autorise qu'une tentative !

    public function __construct(public int $movieId) {
        $this->onConnection('conversions');
        $this->onQueue('conversions');
    }

    public function handle(
        MediaProbe $probe,
        TrackSelector $trackSelector,
        HlsCommandBuilder $hlsCommandBuilder,
        HlsReadinessChecker $hlsReadinessChecker,
        HlsMasterPlaylistBuilder $hlsMasterPlaylistBuilder,
        HlsConverter $hlsConverter,
        MovieInputResolver $movieInputResolver
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

            $fileOrStream = $movieInputResolver->resolve($movie);

            $outputDirectory = Storage::disk('public')->path("movies/{$movie->id}/hls/{$attempt}");

            if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true)) {
                throw new \RuntimeException('Can not create hls directory.');
            }

            $tracksSelected = $trackSelector->select($probe->probe($fileOrStream));

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
                    // Log::channel('my_debug')->info('movie en cours de conversion : ', ['movieId' => $movie->id,'attempt' => $attempt]);
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
                        "Le film est désormais disponible à la lecture, mais sa conversion continue.\n" . json_encode(
                            [
                                'class' => self::class,
                                'method' => 'handle',
                                'movie_id' => $movie->id,
                                'attempt' => $attempt,
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                        ) . "\n",
                    );
                }
            };

            $hlsConverter->convert(
                $hlsCommandBuilder->build($fileOrStream, $outputDirectory, $tracksSelected),
                $publishWhenReady,
            );

            $publishWhenReady();

            if (! $published) {
                throw new \RuntimeException('FFmpeg finished without producing avaible hls segment.');
            }

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
                ->update(
                    [
                        'conversion_status' => ConversionStatus::Failed->value,
                        'conversion_error' => mb_substr($exception->getMessage(), 0, 2000),
                    ]);

            throw ($exception);
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
