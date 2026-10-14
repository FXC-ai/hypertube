<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Exceptions\TorrentClientException;
use App\Models\Movie;
use App\Services\Torrent\TorrentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Follows a movie download on the Client Torrent until it ends, re-dispatching itself
 * every few seconds. Once completed, conversions read the file on disk again.
 *
 * Local test scaffolding: the real stall detection and retries belong to #18.
 */
final class MonitorDownload implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 5;

    public int $tries = 1;

    public function __construct(public int $movieId) {}

    public function handle(TorrentClient $torrentClient): void
    {
        $movie = Movie::query()->find($this->movieId);

        if ($movie === null || $movie->download_status !== DownloadStatus::Downloading || $movie->download_id === null) {
            return;
        }

        try {
            $download = $torrentClient->status($movie->download_id);
        } catch (TorrentClientException) {
            $this->checkAgainLater();

            return;
        }

        $update = match ($download['status'] ?? null) {
            null => [
                'download_status' => DownloadStatus::Failed->value,
                'download_error' => 'The Client Torrent no longer knows this download (restarted?).',
            ],
            'completed' => ['download_status' => DownloadStatus::Completed->value],
            'failed', 'cancelled' => [
                'download_status' => DownloadStatus::Failed->value,
                'download_error' => $download['error'] ?? "Download {$download['status']}.",
            ],
            default => null,
        };

        if ($update === null) {
            $this->checkAgainLater();

            return;
        }

        Movie::query()
            ->whereKey($movie->id)
            ->where('download_id', $movie->download_id)
            ->update($update);
    }

    private function checkAgainLater(): void
    {
        self::dispatch($this->movieId)->delay(now()->addSeconds(self::POLL_SECONDS));
    }
}
