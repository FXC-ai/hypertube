<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexMovieRequest;

use App\Enums\ConversionStatus;
use App\Http\Resources\CommentResource;
use App\Http\Resources\IndexMovieResource;
use App\Models\Movie;
use App\Models\MovieData;
use App\Services\OmdbService;
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
        $enrichedData = $this->getEnrichedData($movie);

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
                    'movieData' => $enrichedData,
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

        Log::channel("my_debug")->debug("search = ", [$search]);

        $query = Movie::query();

        if ($search) {
            $query->where('title', 'like', '%' . $search . '%');
        }

        if ($sort) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        }

        return Inertia::render(
            'movies/index',
            [
                "movies" => Inertia::scroll(
                    fn() => IndexMovieResource::collection($query->paginate($perPage))
                ),
                'filters' => [
                    'search' => $search,
                ],
            ]
        );
    }

    /**
     * Fetch enriched data from OMDb if available or cache it.
     */
    private function getEnrichedData(Movie $movie): ?array
    {
        if ($movie->imdb_id == null) {
            return null;
        }

        $omdbService = OmdbService::factory();
        $enriched = $omdbService->enrichByImdbId($movie->imdb_id);

        // Link the movie_data row to this movie if it was just created
        if ($enriched !== null) {
            $movieData = MovieData::findByImdbId($movie->imdb_id);
            if ($movieData && $movieData->movie_id === null) {
                $omdbService->linkMovie($movieData, $movie->id);
            }
        } else {
            // TODO: Log
        }

        return $enriched;
    }
}
