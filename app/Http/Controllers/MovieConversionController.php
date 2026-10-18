<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Jobs\ConvertMovie;
use App\Jobs\DownloadMovie;
use App\Models\Movie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

final class MovieConversionController extends Controller
{
    public function store(Movie $movie): RedirectResponse
    {
        $context = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'movie_id' => $movie->id,
            'user_id' => request()->user()?->getAuthIdentifier(),
        ];

        Log::channel('my_debug')->info(
            "Une demande de préparation du film a été reçue.\n" . json_encode(
                [
                    ...$context,
                    'download_status' => $movie->download_status?->value,
                    'conversion_status' => $movie->conversion_status?->value,
                    'has_torrent' => $movie->torrent_url !== null,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ) . "\n",
        );

        if ($movie->download_status === DownloadStatus::Unsupported) {
            Log::channel('my_debug')->debug(
                "La préparation du film est refusée car son torrent n’est pas pris en charge.\n" . json_encode(
                    [...$context, 'reason' => 'torrent_unsupported'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );

            return to_route('movies.show', $movie);
        }

        if (
            $movie->torrent_url !== null &&
            ! in_array($movie->download_status, [DownloadStatus::Downloading, DownloadStatus::Completed], true)
        ) {
            DownloadMovie::dispatch($movie->id)->afterCommit();

            Log::channel('my_debug')->info(
                "Le job de téléchargement du film a été ajouté à la file d’attente.\n" . json_encode(
                    [
                        ...$context,
                        'previous_download_status' => $movie->download_status?->value,
                        'job' => DownloadMovie::class,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );

            return to_route('movies.show', $movie);
        }

        $queued = Movie::query()
            ->whereKey($movie->id)
            ->where('download_status', '!=', DownloadStatus::Unsupported->value)
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
            ConvertMovie::dispatch($movie->id)->afterCommit();

            Log::channel('my_debug')->info(
                "Le job de conversion du film a été ajouté à la file d’attente.\n" . json_encode(
                    [
                        ...$context,
                        'previous_conversion_status' => $movie->conversion_status?->value,
                        'conversion_status' => ConversionStatus::Queued->value,
                        'job' => ConvertMovie::class,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n",
            );
        }

        return to_route('movies.show', $movie);
    }

    public function show(Movie $movie): Response
    {
        return Inertia::render(
            'movies/show',
            [
                'conversion' => [

                    'status' => $movie->conversion_status->value,
                    'attempt' => $movie->conversion_attempt,
                    'error' => $movie->conversion_status === ConversionStatus::Failed ? $movie->conversion_error : null,
                    'playable' => $movie->isPlayable(),
                ],
            ]
        );
    }
}
