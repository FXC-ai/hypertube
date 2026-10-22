<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Enums\MovieSource;
use App\Http\Requests\IndexMovieRequest;
use App\Http\Requests\SelectYtsMovieRequest;
use App\Http\Resources\CommentResource;
use App\Models\Movie;
use App\Services\Sources\SourceRegistry;
use App\Services\Sources\YtsSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MovieController extends Controller
{
    public function __construct(
        private SourceRegistry $registry
    ) {
    }

    /**
     * Display the specified resource.
     */
    public function show(Movie $movie): InertiaResponse
    {
        return Inertia::render(
            'movies/show',
            [
                'moviePageData' => [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'filename' => $movie->filename,
                    'source' => $movie->source?->value,
                    'conversion_attempt' => $movie->conversion_attempt,
                    'conversion_status' => $movie->conversion_status->value,
                    'conversion_error' => $movie->conversion_status === ConversionStatus::Failed ? $movie->conversion_error : null,
                    'playable' => $movie->isPlayable(),
                    'preferredlanguage' => Auth::user()->preferredlanguage?->value,
                    'movieData' => $movie->getEnrichedData(),
                ],
                'comments' => Inertia::scroll(
                    fn() => CommentResource::collection(
                        $movie->comments()->with('user')->latest()->paginate(20)
                    ),
                ),
            ]
        );
    }

    /**
     * Search every source the user has activated, and merge the results.
     */
    public function index(IndexMovieRequest $request): InertiaResponse
    {
        $params = $request->validated();

        $search = $params['search'] ?? null;
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['perPage'] ?? 5);

        $sources = $request->user()->enabledSources();

        $items = [];
        $total = 0;

        foreach ($sources as $source) {
            $result = $this->registry->provider($source)->search($request, $search ?? '', $page, $perPage);
            $items = array_merge($items, $result['items']);
            $total += $result['total'];
        }

        $movies = new Paginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return Inertia::render(
            'movies/index',
            [
                'movies' => Inertia::scroll(fn() => $movies),
                'filters' => [
                    'search' => $search,
                    'sources' => array_map(fn(MovieSource $source): string => $source->value, $sources),
                ],
            ]
        );
    }

    /**
     * Materialize a YTS result picked by the user so it can be downloaded and streamed.
     */
    public function selectYts(SelectYtsMovieRequest $request): RedirectResponse
    {
        $imdbCode = $request->validated()['imdb_code'];

        $source = YtsSource::factory();
        $found = $source->fetchByImdbId($imdbCode);

        if ($found === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('YTS did not return this movie.')]);

            return to_route('movies.index');
        }

        $movie = $source->materialize($found);

        if ($movie === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No seeded torrent available for this movie on YTS.')]);

            return to_route('movies.index');
        }

        return to_route('movies.show', $movie);
    }
}
