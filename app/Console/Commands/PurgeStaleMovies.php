<?php

namespace App\Console\Commands;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Enums\ExternalSubtitleStatus;
use App\Models\Movie;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Signature('movies:purge-stale')]
#[Description('Delete movie files that have not been watched for one month')]
final class PurgeStaleMovies extends Command implements Isolatable
{
    private const BATCH_SIZE = 100;

    public function isolationLockExpiresAt(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(): int
    {
        $cutoff = now()->subMonthNoOverflow();
        $purged = 0;
        $failed = 0;

        foreach ($this->interruptedPurges() as $movie) {
            $result = $this->purgeMovie($movie->id, $cutoff);
            $purged += $result === true ? 1 : 0;
            $failed += $result === false ? 1 : 0;
        }

        foreach ($this->newCandidates($cutoff) as $movie) {
            $result = $this->purgeMovie($movie->id, $cutoff);
            $purged += $result === true ? 1 : 0;
            $failed += $result === false ? 1 : 0;
        }

        $this->components->info("Purged {$purged} movie(s); {$failed} failure(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return iterable<int, Movie>
     */
    private function interruptedPurges(): iterable
    {
        return Movie::query()
            ->where('download_status', DownloadStatus::Purging->value)
            ->select('id')
            ->lazyById(self::BATCH_SIZE);
    }

    /**
     * @return iterable<int, Movie>
     */
    private function newCandidates(CarbonInterface $cutoff): iterable
    {
        return Movie::query()
            ->where('download_status', DownloadStatus::Completed->value)
            ->whereIn('conversion_status', [
                ConversionStatus::Pending->value,
                ConversionStatus::Failed->value,
                ConversionStatus::Converted->value,
            ])
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('last_watched_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('last_watched_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->select('id')
            ->lazyById(self::BATCH_SIZE);
    }

    /**
     * true: purged; false: failed; null: no longer eligible.
     */
    private function purgeMovie(int $movieId, CarbonInterface $cutoff): ?bool
    {
        try {
            $claimed = DB::transaction(function () use ($movieId, $cutoff): bool {
                $movie = Movie::query()
                    ->lockForUpdate()
                    ->find($movieId);

                if ($movie === null) {
                    return false;
                }

                if ($movie->download_status === DownloadStatus::Purging) {
                    return true;
                }

                if (! $this->isNewCandidate($movie, $cutoff)) {
                    return false;
                }

                $movie->download_status = DownloadStatus::Purging;
                $movie->save();

                return true;
            }, attempts: 3);

            if (! $claimed) {
                return null;
            }

            $disk = Storage::disk('public');
            $directory = "movies/{$movieId}";
            $deleted = ! $disk->directoryExists($directory) || $disk->deleteDirectory($directory);

            if (! $deleted) {
                throw new RuntimeException("Unable to delete {$directory}.");
            }

            $updated = Movie::query()
                ->whereKey($movieId)
                ->where('download_status', DownloadStatus::Purging->value)
                ->update([
                    'filename' => null,
                    'download_status' => DownloadStatus::Pending->value,
                    'download_id' => null,
                    'download_file_index' => null,
                    'download_error' => null,
                    'conversion_status' => ConversionStatus::Pending->value,
                    'conversion_attempt' => null,
                    'conversion_error' => null,
                    'conversion_started_at' => null,
                    'conversion_playable_at' => null,
                    'conversion_completed_at' => null,
                    'audio_language' => null,
                    'external_subtitles' => null,
                    'external_subtitles_status' => ExternalSubtitleStatus::None->value,
                    'last_watched_at' => null,
                ]);

            if ($updated !== 1) {
                throw new RuntimeException("Unable to reset movie {$movieId}.");
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('Movie purge failed.', [
                'movie_id' => $movieId,
                'exception' => $exception,
            ]);

            $this->components->error(
                "Movie {$movieId} could not be purged: {$exception->getMessage()}",
            );

            return false;
        }
    }

    private function isNewCandidate(Movie $movie, CarbonInterface $cutoff): bool
    {
        if ($movie->download_status !== DownloadStatus::Completed) {
            return false;
        }

        if (! in_array($movie->conversion_status, [
            ConversionStatus::Pending,
            ConversionStatus::Failed,
            ConversionStatus::Converted,
        ], true)) {
            return false;
        }

        $referenceDate = $movie->last_watched_at ?? $movie->updated_at;

        return $referenceDate !== null && $referenceDate->lessThanOrEqualTo($cutoff);
    }
}
