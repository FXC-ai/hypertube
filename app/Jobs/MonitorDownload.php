<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Enums\ExternalSubtitleStatus;
use App\Exceptions\TorrentClientException;
use App\Models\Movie;
use App\Services\Torrent\TorrentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

    public function __construct(public int $movieId)
    {
        $this->onConnection('monitoring');
        $this->onQueue('monitoring');
    }

    public function handle(TorrentClient $torrentClient): void
    {
        $context = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'movie_id' => $this->movieId,
        ];
        $movie = Movie::query()->find($this->movieId);

        if ($movie === null || $movie->download_status !== DownloadStatus::Downloading || $movie->download_id === null) {

            $reason = match (true) {
                $movie === null => 'movie_not_found',
                $movie->download_id === null => 'download_id_missing',
                default => 'download_not_in_progress',
            };

            Log::channel('my_debug')->debug(
                "La surveillance du téléchargement est arrêtée car elle n’est plus nécessaire.\n".json_encode(
                    [
                        ...$context,
                        'reason' => $reason,
                        'download_status' => $movie?->download_status?->value,
                        'download_id' => $movie?->download_id,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            return;
        }

        try {
            $download = $torrentClient->status($movie->download_id);

            Log::channel('my_debug')->info(
                'Statut du téléchargement : '.json_encode(
                    [
                        'movie_id' => $this->movieId,
                        'id' => $download['id'] ?? $movie->download_id,
                        'status' => $download['status'] ?? null,
                        'piecesCompleted' => isset($download['piecesCompleted'], $download['totalPieces'])
                            ? $download['piecesCompleted'].' / '.$download['totalPieces']
                            : null,
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                )."\n",
            );
        } catch (TorrentClientException $exception) {
            Log::channel('my_debug')->warning(
                "Le statut du téléchargement n’a pas pu être récupéré. Une nouvelle vérification sera programmée.\n".json_encode(
                    [
                        ...$context,
                        'retry_in_seconds' => self::POLL_SECONDS,
                        'exception' => [
                            'type' => $exception::class,
                            'message' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );
            $this->checkAgainLater();

            return;
        }

        $hasExternalSubtitles = ($movie->external_subtitles ?? []) !== [];

        $update = match ($download['status'] ?? null) {
            null => [
                'download_status' => DownloadStatus::Failed->value,
                'download_error' => 'The Client Torrent no longer knows this download (restarted?).',
                'external_subtitles_status' => $hasExternalSubtitles
                    ? ExternalSubtitleStatus::Failed->value
                    : ExternalSubtitleStatus::None->value,
            ],
            'completed' => [
                'download_status' => DownloadStatus::Completed->value,
                'external_subtitles_status' => $hasExternalSubtitles
                    ? ExternalSubtitleStatus::Queued->value
                    : ExternalSubtitleStatus::None->value,
            ],
            'failed', 'cancelled' => [
                'download_status' => DownloadStatus::Failed->value,
                'download_error' => $download['error'] ?? "Download {$download['status']}.",
                'external_subtitles_status' => $hasExternalSubtitles
                    ? ExternalSubtitleStatus::Failed->value
                    : ExternalSubtitleStatus::None->value,
            ],
            default => null,
        };

        if ($update === null) {
            $this->checkAgainLater();

            return;
        }

        $updated = Movie::query()
            ->whereKey($movie->id)
            ->where('download_id', $movie->download_id)
            ->where('download_status', DownloadStatus::Downloading->value)
            ->update($update);

        if ($updated !== 1) {
            Log::channel('my_debug')->debug(
                "Le statut du téléchargement n’a pas été modifié car le téléchargement a changé entre-temps.\n".json_encode(
                    [
                        ...$context,
                        'target_status' => $update['download_status'],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            return;
        }

        if ($update['download_status'] === DownloadStatus::Completed->value) {
            if ($hasExternalSubtitles) {
                PrepareExternalSubtitles::dispatch($movie->id)->afterCommit();
            }

            Log::channel('my_debug')->info(
                "Le téléchargement du film est terminé.\n".json_encode(
                    [
                        ...$context,
                        'download_status' => DownloadStatus::Completed->value,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            return;
        }

        Log::channel('my_debug')->error(
            "Le téléchargement du film s’est terminé en erreur.\n".json_encode(
                [
                    ...$context,
                    'download_status' => DownloadStatus::Failed->value,
                    'download_error' => $update['download_error'],
                    'client_status' => $download['status'] ?? null,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            )."\n",
        );
    }

    private function checkAgainLater(): void
    {
        self::dispatch($this->movieId)->delay(now()->addSeconds(self::POLL_SECONDS));
    }
}
