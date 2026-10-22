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
use Log;

class MovieController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(Movie $movie): InertiaResponse
    {
        WatchedMovie::markWatched(Auth::user(), $movie);

        return Inertia::render(
            'movies/show',
            [
                'moviePageData' => [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'filename' => $movie->filename,
                    'conversion_attempt' => $movie->conversion_attempt,
                    'conversion_status' => $movie->conversion_status->value,
                    'conversion_error' => $movie->conversion_status === ConversionStatus::Failed ? $movie->conversion_error : null,
                    'playable' => $movie->isPlayable(),
                    'preferredlanguage' => Auth::user()->preferredlanguage?->value,
                    'movieData' => $movie->getEnrichedData(),
                ],
                'comments' => Inertia::scroll(
                    fn () => CommentResource::collection(
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
                'watchedMovies as watched' => fn (Builder $query) => $query
                    ->where('watched_movies.user_id', $userId),
            ]);

        if ($search) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        if ($watched === WatchedFilter::Watched) {
            $query->whereHas('watchedMovies', fn (Builder $query) => $query->where('watched_movies.user_id', $userId));
        } elseif ($watched === WatchedFilter::Unwatched) {
            $query->whereDoesntHave('watchedMovies', fn (Builder $query) => $query->where('watched_movies.user_id', $userId));
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
                    fn () => IndexMovieResource::collection($query->paginate($perPage))
                ),
                'filters' => [
                    'search' => $search,
                    'watched' => $watched->value,
                ],
            ]
        );
    }
}
