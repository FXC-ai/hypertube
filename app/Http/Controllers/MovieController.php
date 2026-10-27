<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Enums\WatchedFilter;
use App\Http\Requests\IndexMovieRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\IndexMovieResource;
use App\Models\Movie;
use App\Models\WatchedMovie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Illuminate\Support\Facades\Log;
use App\Enums\DownloadStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class MovieController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(Movie $movie): InertiaResponse
    {
        $movie = DB::transaction(function () use ($movie): Movie {
            $lockedMovie = Movie::query()
                ->lockForUpdate()
                ->findOrFail($movie->id);

            if ($lockedMovie->download_status !== DownloadStatus::Purging) {
                WatchedMovie::markWatched(Auth::user(), $lockedMovie);
            }

            return $lockedMovie;
        }, attempts: 3);

        return Inertia::render(
            'movies/show',
            [
                'moviePageData' => [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'filename' => $movie->filename,
                    'conversion_attempt' => $movie->conversion_attempt,
                    'conversion_status' => $movie->conversion_status->value,
                    'conversion_error' => $movie->conversion_status === ConversionStatus::Failed ? 'The conversion failed.' : null,
                    'playable' => $movie->isPlayable(),
                    'preferredlanguage' => Auth::user()->preferredlanguage?->value,
                    'movieData' => $movie->getEnrichedData(),
                    'download_status' => $movie->download_status->value,
                    'download_error' => match ($movie->download_status) {
                        DownloadStatus::Failed => 'This movie is not available.',
                        DownloadStatus::Unsupported => $movie->download_error,
                        default => null,
                    },
                    'can_prepare' => ! in_array($movie->download_status, [
                        DownloadStatus::Unsupported,
                        DownloadStatus::Purging,
                    ], true) && in_array($movie->conversion_status, [
                        ConversionStatus::Pending,
                        ConversionStatus::Failed,
                    ], true),
                    'external_subtitles_status' => $movie->external_subtitles_status->value,
                    'external_subtitles' => $movie->download_status === DownloadStatus::Purging
                        ? [] : collect($movie->external_subtitles ?? [])
                        ->filter(fn(array $subtitle): bool => is_string($subtitle['output_file'] ?? null))
                        ->map(fn(array $subtitle): array => [
                            'id' => $subtitle['file_index'],
                            'label' => $subtitle['file_name'] . ' — External',
                            'language' => $subtitle['language'],
                            'url' => Storage::disk('public')->url(
                                "movies/{$movie->id}/{$subtitle['output_file']}"
                            ),
                        ])
                        ->values()
                        ->all(),
                ],
                'comments' => Inertia::scroll(
                    fn() => CommentResource::collection(
                        $movie->comments()->with('user')->latest()->paginate(20)
                    ),
                ),
            ]
        );
    }

    public function index(IndexMovieRequest $request): InertiaResponse
    {
        $params = $request->validated();

        $search = $params['search'] ?? null;
        $sort = $params['sort'] ?? null;
        $dir = $params['dir'] ?? 'asc';
        $perPage = (int) ($params['perPage'] ?? 5);
        $watched = WatchedFilter::from($params['watched'] ?? WatchedFilter::All->value);

        Log::channel('my_debug')->debug('search = ', [$search]);

        $userId = Auth::id();

        $query = Movie::query()
            ->withExists([
                'watchedMovies as watched' => fn(Builder $query) => $query
                    ->where('watched_movies.user_id', $userId),
            ]);

        if ($search) {
            $query->where('title', 'like', '%' . $search . '%');
        }

        if ($watched === WatchedFilter::Watched) {
            $query->whereHas('watchedMovies', fn(Builder $query) => $query->where('watched_movies.user_id', $userId));
        } elseif ($watched === WatchedFilter::Unwatched) {
            $query->whereDoesntHave('watchedMovies', fn(Builder $query) => $query->where('watched_movies.user_id', $userId));
        }

        if ($sort) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        }

        return Inertia::render(
            'movies/index',
            [
                'movies' => Inertia::scroll(
                    fn() => IndexMovieResource::collection($query->paginate($perPage))
                ),
                'filters' => [
                    'search' => $search,
                    'watched' => $watched->value,
                ],
            ]
        );
    }
}
