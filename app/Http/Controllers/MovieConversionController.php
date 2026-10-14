<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Jobs\ConvertMovie;
use App\Models\Movie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

use App\Jobs\DownloadMovie;
use App\Enums\DownloadStatus;

final class MovieConversionController extends Controller
{
    public function store(Movie $movie): RedirectResponse
    {
        Log::channel('my_debug')->debug('MovieConversionController', ['Method' => 'store']);

        if
        (
            $movie->torrent_url !== null && 
            ! in_array($movie->download_status, [DownloadStatus::Downloading, DownloadStatus::Completed], true)
        )
        {
            DownloadMovie::dispatch($movie->id)->afterCommit();

            return to_route('movies.show', $movie);
        }

        $queued = Movie::query()
            ->whereKey($movie->id)
            ->whereIn('conversion_status', [ConversionStatus::Pending->value, ConversionStatus::Failed->value])
            ->update(
                [
                    'conversion_status' => ConversionStatus::Queued->value,
                    'conversion_attempt' => null,
                    'conversion_error' => null,
                    'conversion_started_at' => null,
                    'conversion_playable_at' => null,
                    'conversion_completed_at' => null,
                ]
            );

        if ($queued === 1) {

            ConvertMovie::dispatch($movie->id)->afterCommit();
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
