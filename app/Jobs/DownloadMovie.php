<?php

namespace App\Jobs;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Models\Movie;
use App\Services\Torrent\TorrentClient;
use App\Services\Torrent\TorrentVideoSelector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;


final class DownloadMovie implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $movieId)
    {
        $this->onConnection('monitoring');
        $this->onQueue('monitoring');
    }

    public function handle(TorrentClient $torrentClient, TorrentVideoSelector $videoSelector): void
    {
        $context = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'movie_id' => $this->movieId,
        ];

        Log::channel('my_debug')->info(
            "Le job de téléchargement du film démarre.\n" . json_encode(
                [...$context],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ) . "\n",
        );

        $claimed = Movie::query()
            ->whereKey($this->movieId)
            ->whereNotNull('torrent_url')
            ->whereIn('download_status', [
                DownloadStatus::Pending->value,
                DownloadStatus::Failed->value
            ])
            ->update([
                'download_status' => DownloadStatus::Downloading->value,
                'download_id' => null,
                'download_file_index' => null,
                'download_error' => null,
            ]);

        if ($claimed !== 1) {
            $movie = Movie::query()->find($this->movieId);

            $reason = match (true) {
                $movie === null => 'movie_not_found',
                $movie->torrent_url === null => 'torrent_url_missing',
                $movie->download_status === DownloadStatus::Unsupported => 'torrent_unsupported',
                default => 'download_status_not_eligible',
            };

            Log::channel('my_debug')->debug(
                "Le téléchargement du film ne peut pas démarrer.\n" . json_encode(
                    [
                        ...$context,
                        'reason' => $reason,
                        'download_status' => $movie?->download_status?->value,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );

            return;
        }

        $movie = Movie::query()->findOrFail($this->movieId);

        try {
            $inspection = $torrentClient->inspect($movie->torrent_url);
            $selectedVideo = $videoSelector->select($inspection['files']);

            Log::channel('my_debug')->info(
                "L’inspection du torrent est terminée.\n" . json_encode(
                    [
                        ...$context,
                        'file_count' => count($inspection['files']),
                        'selected_video' => $selectedVideo,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );

            if ($selectedVideo === null) {
                $reason = 'The torrent contains no supported MP4 or MKV video file.';

                Movie::query()
                    ->whereKey($movie->id)
                    ->where('download_status', DownloadStatus::Downloading->value)
                    ->update([
                        'filename' => null,
                        'download_status' => DownloadStatus::Unsupported->value,
                        'download_id' => null,
                        'download_file_index' => null,
                        'download_error' => $reason,
                    ]);

                Log::channel('my_debug')->warning(
                    "Le torrent ne contient aucune vidéo MP4 ou MKV prise en charge.\n" . json_encode(
                        [...$context, 'reason' => 'supported_video_missing'],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                    ) . "\n",
                );

                return;
            }

            $downloadId = $torrentClient->startDownload(
                $movie->torrent_url,
                Storage::disk('public')->path("movies/{$movie->id}"),
                [$selectedVideo['index']],
                $inspection['infoHash'],
            );

            Log::channel('my_debug')->info(
                "Le client torrent a accepté la demande de téléchargement.\n" . json_encode(
                    [
                        ...$context,
                        'download_id' => $downloadId
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );

            Movie::query()->whereKey($movie->id)->update([
                'download_id' => $downloadId,
                'download_file_index' => $selectedVideo['index'],
                'filename' => $selectedVideo['fileName'],
            ]);

            Log::channel('my_debug')->info(
                "Les informations du téléchargement ont été enregistrées pour le film.\n" . json_encode(
                    [
                        ...$context,
                        'download_id' => $downloadId,
                        'file_index' => $selectedVideo['index'],
                        'filename' => $selectedVideo['fileName'],
                        'selected_file_count' => 1,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
        } catch (Throwable $exception) {
            Movie::query()->whereKey($movie->id)->update([
                'download_status' => DownloadStatus::Failed->value,
                'download_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            Log::channel('my_debug')->error(
                "Le téléchargement du film a échoué.\n" . json_encode(
                    [
                        ...$context,
                        'exception' => [
                            'type' => $exception::class,
                            'message' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );

            throw $exception;
        }

        $queued = Movie::query()
            ->whereKey($movie->id)
            ->where('download_status', DownloadStatus::Downloading->value)
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

            Log::channel('my_debug')->info(
                "Le job de conversion du film a été ajouté à la file d’attente.\n" . json_encode(
                    [
                        ...$context,
                        'download_id' => $downloadId,
                        'conversion_status' => ConversionStatus::Queued->value,
                        'job' => ConvertMovie::class,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
        } else {
            $movie->refresh();

            Log::channel('my_debug')->debug(
                "La conversion du film n’a pas été ajoutée à la file d’attente car son statut ne le permet pas.\n" . json_encode(
                    [
                        ...$context,
                        'download_id' => $downloadId,
                        'conversion_status' => $movie->conversion_status?->value,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
        }

        MonitorDownload::dispatch($movie->id)->delay(now()->addSeconds(MonitorDownload::POLL_SECONDS));

        Log::channel('my_debug')->info(
            "Le suivi du téléchargement a été programmé.\n" . json_encode(
                [
                    ...$context,
                    'download_id' => $downloadId,
                    'delay_seconds' => MonitorDownload::POLL_SECONDS,
                    'job' => MonitorDownload::class,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ) . "\n",
        );
    }
}
