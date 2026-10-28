<?php

namespace App\Http\Controllers;

use App\Data\MergedMovie;
use App\Data\MovieSearch;
use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
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
use Illuminate\Support\Facades\Storage;
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

    /**
     * Search every source the user has activated, and merge the results.
     */
    public function index(IndexMovieRequest $request): InertiaResponse
    {
        $params = $request->validated();

        $search = $params['search'] ?? null;
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['perPage'] ?? 5);
        $dir = ($params['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $watched = WatchedFilter::from($params['watched'] ?? WatchedFilter::All->value);
        $userId = Auth::id();

        $sources = $request->user()->enabledSources();
        $criteria = new MovieSearch(
            term: $search ?? '',
            page: $page,
            perPage: $perPage,
            sort: $params['sort'] ?? 'created_at',
            direction: $dir,
            watched: $watched,
            userId: $userId,
        );

        $entries = [];
        $seen = [];
        $duplicates = 0;
        $total = 0;

        foreach ($sources as $source) {
            $result = $this->registry->provider($source)->search($criteria);

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

        usort($entries, function (Source $a, Source $b) use ($dir): int {
            $comparison = ($a->created_at?->getTimestamp() ?? 0) <=> ($b->created_at?->getTimestamp() ?? 0);

            if ($comparison === 0) {
                $comparison = $a->id <=> $b->id;
            }

            return $dir === 'asc' ? $comparison : -$comparison;
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

        $movies = new Paginator(
            $results,
            max($total - $duplicates, 0),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return Inertia::render(
            'movies/index',
            [
                'movies' => Inertia::scroll(fn() => IndexMovieResource::collection($movies)),
                'filters' => [
                    'search' => $search,
                    'watched' => $watched->value,
                    'sources' => array_map(fn(MovieSource $source): string => $source->value, $sources),
                ],
            ]
        );
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
