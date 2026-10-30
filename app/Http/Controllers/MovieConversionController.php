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
    private const LOG_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public function store(Movie $movie): RedirectResponse
    {
        $this->prepare($movie);

        return to_route('movies.show', $movie);
    }

    /**
     * Launch the background pipeline a video page needs: download it first when the
     * server does not have it yet, then convert it when it can be read.
     */
    public function prepare(Movie $movie): void
    {
        // A movie created by `firstOrCreate` carries no status yet: the defaults only
        // exist on the row the database just wrote.
        $movie = $movie->refresh();

        $context = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'movie_id' => $movie->id,
            'user_id' => request()->user()?->getAuthIdentifier(),
            'download_status' => $movie->download_status->value,
            'conversion_status' => $movie->conversion_status->value,
            'has_torrent' => $movie->torrent_url !== null,
        ];

        if (in_array($movie->download_status, [DownloadStatus::Purging, DownloadStatus::Unsupported], true)) {
            $this->log('La préparation du film est refusée par son statut de téléchargement.', $context, 'debug');

            return;
        }

        if (
            $movie->torrent_url !== null &&
            ! in_array(
                $movie->download_status,
                [DownloadStatus::Downloading, DownloadStatus::Completed, DownloadStatus::Purging],
                true
            )
        ) {
            DownloadMovie::dispatch($movie->id)->afterCommit();

            $this->log('Le job de téléchargement du film a été ajouté à la file d’attente.', [...$context, 'job' => DownloadMovie::class]);

            return;
        }

        $queued = Movie::query()
            ->whereKey($movie->id)
            ->whereNotIn('download_status', [DownloadStatus::Unsupported->value, DownloadStatus::Purging->value])
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

            $this->log('Le job de conversion du film a été ajouté à la file d’attente.', [...$context, 'job' => ConvertMovie::class]);

            return;
        }

        $this->log(
            'Aucun job n’a été ajouté à la file : le film n’attend ni téléchargement ni conversion.',
            [...$context, 'rows_queued' => $queued],
            'debug',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $message, array $context, string $level = 'info'): void
    {
        $line = $message."\n".json_encode($context, self::LOG_FLAGS)."\n";
        $logger = Log::channel('my_debug');

        if ($level === 'debug') {
            $logger->debug($line);

            return;
        }

        $logger->info($line);
    }

    public function show(Movie $movie): Response
    {
        return Inertia::render(
            'movies/show',
            [
                'conversion' => [
                    'status' => $movie->conversion_status->value,
                    'attempt' => $movie->conversion_attempt,
                    'error' => $movie->conversion_status === ConversionStatus::Failed ? 'The conversion failed.' : null,
                    'playable' => $movie->isPlayable(),
                ],
            ]
        );
    }
}
