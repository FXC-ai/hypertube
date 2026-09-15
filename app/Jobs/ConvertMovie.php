<?php

namespace App\Jobs;

use App\Data\SelectedTracks;
use App\Enums\ConversionStatus;
use App\Models\Movie;
use App\Services\Media\HlsCommandBuilder;
use App\Services\Media\HlsConverter;
use App\Services\Media\HlsMasterPlaylistBuilder;
use App\Services\Media\HlsReadinessChecker;
use App\Services\Media\MediaProbe;
use App\Services\Media\TrackSelector;
use Exception;
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
        HlsConverter $hlsConverter
    ): void {

        Log::channel("my_debug")->debug("Etape 2 : ", ["ConvertMovie" => "handle"]);

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

        Log::channel("my_debug")->debug("ConvertMovie", ["movie" => json_encode($movie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        try {

            if (preg_match('/[\\\\\/]/', $movie->filename) === 1 || $movie->filename !== basename($movie->filename)) {
                throw new \RuntimeException('Nom de fichier source invalide.');
            }

            $inputPath = Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}");
            $outputDirectory = Storage::disk('public')->path("movies/{$movie->id}/hls/{$attempt}");

            if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true) && ! is_dir($outputDirectory)) {
                throw new \RuntimeException('Can not create hls directory.');
            }

            $tracksSelected = $trackSelector->select($probe->probe($inputPath));

            Log::channel("my_debug")->debug("ConvertMovie ", ["tracksSelected" => json_encode($tracksSelected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

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

                Log::channel("my_debug")->debug("publishWhenReady", ["called"]);

                if ($published || ! $hlsReadinessChecker->isReady($outputDirectory, $tracksSelected)) {
                    Log::channel("my_debug")->debug("publishWhenReady stops", [$tracksSelected]);
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
                $commands->build($inputPath, $outputDirectory, $tracksSelected),
                $publishWhenReady,
            );

            Log::channel("my_debug")->debug("ConverMovie", ["handle ended, la conversion est terminée."]);
        } catch (Throwable $exception) {
            Log::channel("my_debug")->debug("ConvertMovie", ["exception : ", $exception]);


            Log::error('Échec de conversion HLS.', [
                'movie_id' => $movie->id,
                'attempt' => $attempt,
                'exception' => $exception,
            ]);
        }
    }
}
