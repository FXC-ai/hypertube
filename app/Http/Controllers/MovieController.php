<?php

namespace App\Http\Controllers;

use App\Data\MergedMovie;
use App\Data\MovieSearch;
use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Enums\MovieSort;
use App\Enums\MovieSource;
use App\Enums\WatchedFilter;
use App\Http\Requests\IndexMovieRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\IndexMovieResource;
use App\Models\Movie;
use App\Models\Source;
use App\Models\WatchedMovie;
use App\Services\OmdbService;
use App\Services\Sources\SourceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
                    'title' => $movie->title ?? $movie->imdb_id,
                    'filename' => $movie->filename,
                    'source' => $movie->source?->value,
                    'conversion_attempt' => $movie->conversion_attempt,
                    'conversion_status' => $movie->conversion_status->value,
                    'conversion_error' => $movie->conversion_status === ConversionStatus::Failed ? 'The conversion failed.' : null,
                    'playable' => $movie->isPlayable(),
                    'preferredlanguage' => Auth::user()->preferredlanguage?->value,
                    'movieData' => $movie->getEnrichedData()?->displayArray(),
                    'download_status' => $movie->download_status->value,
                    'download_error' => match ($movie->download_status) {
                        DownloadStatus::Failed => 'This movie is not available.',
                        DownloadStatus::Unsupported => $movie->download_error,
                        default => null,
                    },
                    'can_prepare' => !in_array($movie->download_status, [
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

    /**
     * Search every source the user has activated, and merge the results.
     */
    public function index(IndexMovieRequest $request): InertiaResponse
    {
        $params = $request->validated();

        $search = $params['search'] ?? null;
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['perPage'] ?? 5);
        $requestDir = $params['dir'] ?? null;
        $watched = WatchedFilter::from($params['watched'] ?? WatchedFilter::All->value);
        $userId = Auth::id();

        $sources = $request->user()->enabledSources();
        $sourceValues = array_map(fn(MovieSource $source): string => $source->value, $sources);
        // An empty listing shows the most popular films first, a search is ordered by
        // name, and an explicit criterion always wins over both.
        $sort = isset($params['sort'])
            ? MovieSort::from($params['sort'])
            : (filled($search) ? MovieSort::Name : MovieSort::Popularity);
        $dir = ($requestDir ?? ($sort === MovieSort::Popularity ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';
        $genre = filled($params['genre'] ?? null) ? (string) $params['genre'] : null;

        $criteria = new MovieSearch(
            term: $search ?? '',
            page: $page,
            perPage: $perPage,
            sort: $sort,
            direction: $dir,
            watched: $watched,
            userId: $userId,
            genre: $genre,
            yearFrom: filled($params['yearFrom'] ?? null) ? (int) $params['yearFrom'] : null,
            yearTo: filled($params['yearTo'] ?? null) ? (int) $params['yearTo'] : null,
            ratingFrom: filled($params['ratingFrom'] ?? null) ? (float) $params['ratingFrom'] : null,
            ratingTo: filled($params['ratingTo'] ?? null) ? (float) $params['ratingTo'] : null,
        );

        $entries = [];
        $seen = [];
        $duplicates = 0;
        $total = 0;
        $exhausted = true;

        foreach ($sources as $source) {
            $result = $this->registry->provider($source)->search($criteria);

            // A source handing over fewer rows than the window asked for has nothing
            // left to give, which is the only way to know the merged listing is complete.
            if (count($result['items']) >= $criteria->windowSize()) {
                $exhausted = false;
            }

            foreach ($result['items'] as $entry) {
                if (isset($seen[$entry->imdb_id])) {
                    // The same movie offered by several sources is only listed once.
                    $duplicates++;

                    continue;
                }

                $seen[$entry->imdb_id] = true;
                $entries[] = $entry;
            }

            $total += $result['total'];
        }

        usort($entries, function (Source $a, Source $b) use ($sort, $dir): int {
            $valueA = $this->sortValue($a, $sort);
            $valueB = $this->sortValue($b, $sort);

            if ($valueA !== null && $valueB !== null) {
                $comparison = $valueA <=> $valueB;

                if ($comparison !== 0) {
                    return $dir === 'asc' ? $comparison : -$comparison;
                }
            } elseif ($valueA !== $valueB) {
                // An entry missing the criterion is ranked last, whichever way the sort goes.
                return $valueA === null ? 1 : -1;
            }

            // The tie breaker has to order the ids the same way every source does, or two
            // pages of the merged listing would overlap when the criterion is unknown.
            return $dir === 'asc' ? $a->id <=> $b->id : $b->id <=> $a->id;
        });

        $window = array_slice($entries, ($page - 1) * $perPage, $perPage);
        $localMovies = Movie::query()
            ->withExists([
                'watchedMovies as watched' => fn(Builder $query) => $query
                    ->where('watched_movies.user_id', $userId),
            ])
            ->whereIn('imdb_id', array_map(fn(Source $entry): string => $entry->imdb_id, $window))
            ->get()
            ->keyBy('imdb_id');
        $omdb = OmdbService::factory();

        $results = array_map(
            fn(Source $entry): MergedMovie => new MergedMovie(
                $entry,
                $localMovies[$entry->imdb_id] ?? null,
                $omdb->enrichByImdbId($entry->imdb_id),
            ),
            $window,
        );

        // Every source is exhausted: the merged listing is complete, and the duplicates
        // of the whole catalog are known, so the count is the exact one. Otherwise the
        // sum of the source totals is the best estimate available.
        $movies = new Paginator(
            $results,
            $exhausted ? count($entries) : max($total - $duplicates, 0),
            $perPage,
            $page,
            // The page of the current request is dropped, the paginator is the one
            // putting the next page in the link the infinite scroll follows.
            ['path' => $request->url(), 'query' => array_diff_key($request->query(), ['page' => ''])]
        );

        return Inertia::render(
            'movies/index',
            [
                'movies' => Inertia::scroll(fn() => IndexMovieResource::collection($movies)),
                'filters' => [
                    'search' => $search,
                    'watched' => $watched->value,
                    'sort' => $sort->value,
                    'dir' => $dir,
                    'genre' => $genre,
                    'yearFrom' => $criteria->yearFrom,
                    'yearTo' => $criteria->yearTo,
                    'ratingFrom' => $criteria->ratingFrom,
                    'ratingTo' => $criteria->ratingTo,
                    'genres' => Source::genreOptions($sourceValues),
                    'sorts' => collect(MovieSort::cases())
                        ->mapWithKeys(fn(MovieSort $case): array => [$case->value => $case->label()])
                        ->all(),
                    'sources' => $sourceValues,
                ],
            ]
        );
    }

    /**
     * The value a merged entry is ranked on, null when the catalog does not know it.
     */
    private function sortValue(Source $entry, MovieSort $sort): int|float|string|null
    {
        return match ($sort) {
            MovieSort::Popularity => $entry->popularity,
            MovieSort::CreatedAt => $entry->created_at?->getTimestamp(),
            MovieSort::Name => $entry->title,
            MovieSort::Year => $entry->release_year,
            MovieSort::Rating => $entry->imdb_rating,
        };
    }

    /**
     * Load a catalog entry on this server: the movie is created from the information
     * of that source, unless this imdb_id is already known locally.
     */
    public function select(MovieSource $source, string $imdb_id): RedirectResponse
    {
        $entry = $this->registry->provider($source)->entry($imdb_id);

        if ($entry === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This movie is not available in that source.')]);

            return to_route('movies.index');
        }

        return to_route('movies.show', Movie::fromCatalogEntry($entry));
    }
}
