<?php

namespace App\Jobs;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Exceptions\TorrentClientException;
use App\Models\Movie;
use App\Services\Torrent\TorrentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Starts the torrent download of a movie, then the conversion right away: while the file
 * downloads, ConvertMovie reads it through the Client Torrent streaming URL (ADR-0007).
 *
 * Local test scaffolding: the real download pipeline (retries, Exhausted...) belongs to #18.
 */
final class DownloadMovie implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $movieId) {}

    public function handle(TorrentClient $torrentClient): void
    {
        $claimed = Movie::query()
            ->whereKey($this->movieId)
            ->whereNotNull('torrent_url')
            ->whereIn('download_status', [DownloadStatus::Pending->value, DownloadStatus::Failed->value])
            ->update([
                'download_status' => DownloadStatus::Downloading->value,
                'download_id' => null,
                'download_error' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $movie = Movie::query()->findOrFail($this->movieId);

        try {
            $inspection = $torrentClient->inspect($movie->torrent_url);
            $mainVideo = collect($inspection['files'])->firstWhere('index', $inspection['mainVideoIndex']);

            if ($mainVideo === null) {
                throw new TorrentClientException('The torrent has no video file.');
            }

            $downloadId = $torrentClient->startDownload(
                $movie->torrent_url,
                Storage::disk('public')->path("movies/{$movie->id}"),
                array_values(array_map(
                    fn (array $file): int => $file['index'],
                    array_filter($inspection['files'], fn (array $file): bool => $file['suggested']),
                )),
                $inspection['infoHash'],
            );

            Movie::query()->whereKey($movie->id)->update([
                'download_id' => $downloadId,
                'download_file_index' => $mainVideo['index'],
                'filename' => $mainVideo['fileName'],
            ]);
        } catch (Throwable $exception) {
            Movie::query()->whereKey($movie->id)->update([
                'download_status' => DownloadStatus::Failed->value,
                'download_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }

        $queued = Movie::query()
            ->whereKey($movie->id)
            ->whereIn('conversion_status', [ConversionStatus::Pending->value, ConversionStatus::Failed->value])
            ->update([
                'conversion_status' => ConversionStatus::Queued->value,
                'conversion_attempt' => null,
                'conversion_error' => null,
                'conversion_started_at' => null,
                'conversion_playable_at' => null,
                'conversion_completed_at' => null,
            ]);

        if ($queued === 1) {
            ConvertMovie::dispatch($movie->id);
        }

        MonitorDownload::dispatch($movie->id)->delay(now()->addSeconds(MonitorDownload::POLL_SECONDS));
    }
}
