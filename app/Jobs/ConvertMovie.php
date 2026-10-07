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

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $movieId) {}

    public function handle(
        MediaProbe $probe,
        TrackSelector $trackSelector,
        HlsCommandBuilder $commands,
        HlsReadinessChecker $hlsReadinessChecker,
        HlsMasterPlaylistBuilder $hlsMasterPlaylistBuilder,
        HlsConverter $hlsConverter,
        MovieInputResolver $inputs,
    ): void {

        Log::channel('my_debug')->debug('Etape 2 : ', ['ConvertMovie' => 'handle']);

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
            return;
        }

        $movie = Movie::query()->findOrFail($this->movieId);

        Log::channel('my_debug')->debug('ConvertMovie', ['movie' => json_encode($movie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        try {

            if (! self::isInsideMovieDirectory($movie->filename)) {
                throw new \RuntimeException('Invalid file name.');
            }

            $input = $inputs->resolve($movie);
            $outputDirectory = Storage::disk('public')->path("movies/{$movie->id}/hls/{$attempt}");

            if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true)) {
                throw new \RuntimeException('Can not create hls directory.');
            }

            $tracksSelected = $trackSelector->select($probe->probe($input));

            Log::channel('my_debug')->debug('ConvertMovie ', ['tracksSelected' => json_encode($tracksSelected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

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

                Log::channel('my_debug')->debug('publishWhenReady', ['called']);

                if ($published || ! $hlsReadinessChecker->isReady($outputDirectory, $tracksSelected)) {
                    Log::channel('my_debug')->debug('publishWhenReady stops', [$tracksSelected]);

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
            };

            $hlsConverter->convert(
                $commands->build($input, $outputDirectory, $tracksSelected),
                $publishWhenReady,
            );

            $publishWhenReady();

            if (! $published) {
                throw new \RuntimeException('FFmpeg finished without producing avaible hls segment
                .');
            }

            Movie::query()
                ->whereKey($movie->id)
                ->where('conversion_attempt', $attempt)
                ->where('conversion_status', ConversionStatus::Playable->value)
                ->update([
                    'conversion_status' => ConversionStatus::Converted->value,
                    'conversion_completed_at' => now(),
                ]);

            Log::channel('my_debug')->debug('ConverMovie', ['handle ended, la conversion est terminée.']);
        } catch (Throwable $exception) {
            Log::channel('my_debug')->error('ConvertMovie', ['exception : ', $exception]);

            Movie::query()
                ->whereKey($movie->id)
                ->where('conversion_attempt', $attempt)
                ->update([
                    'conversion_status' => ConversionStatus::Failed->value,
                    'conversion_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);

            Log::error('Échec de conversion HLS.', [
                'movie_id' => $movie->id,
                'attempt' => $attempt,
                'exception' => $exception,
            ]);

            throw ($exception);
        }
    }

    /**
     * A torrent may keep the movie in a subfolder ("M(1931)/M.1931.mp4"): relative segments are
     * allowed, but nothing that could leave movies/{id}.
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
