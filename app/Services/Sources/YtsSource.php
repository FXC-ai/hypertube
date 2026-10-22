<?php

namespace App\Services\Sources;

use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\MovieData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class YtsSource implements MovieSourceProvider
{
    public function __construct(
        private string $baseUrl
    ) {
    }

    public static function factory(): self
    {
        return new self(rtrim(config('services.sources.yts.base_url', 'https://api.yts.gg'), '/'));
    }

    public function source(): MovieSource
    {
        return MovieSource::Yts;
    }

    /**
     * Live query of the YTS catalog.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(Request $request, string $term, int $page, int $perPage): array
    {
        $payload = $this->request($page, $perPage, $term);

        $movies = $payload['data']['movies'] ?? [];
        $pages = (int) ($payload['data']['info']['pages'] ?? 0);

        $items = [];

        foreach ($movies as $movie) {
            $item = $this->normalize($movie);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'items' => $items,
            'total' => $pages > 0 ? $pages * $perPage : count($items),
        ];
    }

    /**
     * Single movie fetched from YTS by its IMDb code.
     *
     * @return array<string, mixed>|null
     */
    public function fetchByImdbId(string $imdbCode): ?array
    {
        try {
            $response = Http::timeout(30)->retry(2, 200)
                ->get($this->baseUrl . '/api/v1/movies/' . rawurlencode($imdbCode));

            if (!$response->successful()) {
                Log::warning('YTS movie request failed for {imdb_id}: status {status}', [
                    'imdb_id' => $imdbCode,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json('data.movie');
        } catch (Throwable $e) {
            Log::error('YTS movie exception for {imdb_id}: {message}', [
                'imdb_id' => $imdbCode,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Turn a selected YTS movie into a local Movie row so it can go through the
     * download + conversion + streaming pipeline.
     *
     * @param  array<string, mixed>  $movie
     */
    public function materialize(array $movie): ?Movie
    {
        $imdbId = $movie['imdb_code'] ?? null;
        $title = $movie['title'] ?? null;
        $torrent = $this->bestTorrent($movie['torrents'] ?? []);

        if (!$imdbId || !$title || $torrent === null) {
            return null;
        }

        $movieRow = Movie::firstOrCreate(
            ['imdb_id' => $imdbId, 'source' => MovieSource::Yts->value],
            [
                'title' => $title,
                'titles' => json_encode(['en' => $title]),
                'source_reference' => 'yts:' . ($movie['id'] ?? $imdbId),
                'filename' => null,
                'torrent_url' => $torrent['url'] ?? null,
                'seeds' => (int) ($torrent['seeds'] ?? 0),
                'peers' => (int) ($torrent['peers'] ?? 0),
                'torrent_quality' => $torrent['quality'] ?? null,
                'torrent_size_bytes' => isset($torrent['size_bytes']) ? (int) $torrent['size_bytes'] : null,
            ],
        );

        MovieData::storeFromSource(MovieSource::Yts, $imdbId, [
            'poster' => $movie['large_cover_image'] ?? null,
            'plot' => $movie['summary'] ?? $movie['synopsis'] ?? $movie['description_full'] ?? null,
            'genres' => $movie['genres'] ?? null,
            'runtime' => isset($movie['runtime']) ? (int) $movie['runtime'] : null,
            'rated' => $movie['mpa_rating'] ?? null,
            'language' => isset($movie['language']) ? [$movie['language']] : null,
            'release_date' => isset($movie['year']) ? $movie['year'] . '-01-01' : null,
            'imdbRating' => isset($movie['rating']) ? (float) $movie['rating'] : null,
        ]);

        return $movieRow;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(int $page, int $perPage, ?string $term): array
    {
        try {
            $response = Http::timeout(30)->retry(2, 200)
                ->get($this->baseUrl . '/api/v1/movies', array_filter([
                    'page' => $page,
                    'limit' => $perPage,
                    'query_term' => $term,
                ]));

            if (!$response->successful()) {
                Log::warning('YTS search request failed for page {page}: status {status}', [
                    'page' => $page,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $response->json() ?? [];
        } catch (Throwable $e) {
            Log::error('YTS search exception for page {page}: {message}', [
                'page' => $page,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>|null
     */
    private function normalize(array $movie): ?array
    {
        if (($movie['state'] ?? 'ok') !== 'ok') {
            return null;
        }

        $imdbId = $movie['imdb_code'] ?? null;
        $title = $movie['title'] ?? null;

        if (!$imdbId || !$title) {
            return null;
        }

        $torrent = $this->bestTorrent($movie['torrents'] ?? []);

        $display = [
            'imdbID' => $imdbId,
            'year' => isset($movie['year']) ? (string) $movie['year'] : 'N/A',
            'rated' => $movie['mpa_rating'] ?? null,
            'released' => null,
            'runtime' => isset($movie['runtime']) ? (string) $movie['runtime'] . ' min' : 'N/A',
            'genre' => empty($movie['genres']) ? 'N/A' : implode(', ', $movie['genres']),
            'director' => 'N/A',
            'writer' => 'N/A',
            'actors' => 'N/A',
            'plot' => $movie['summary'] ?? $movie['synopsis'] ?? 'No plot available.',
            'language' => $movie['language'] ?? 'N/A',
            'poster' => $movie['large_cover_image'] ?? null,
            'imdbRating' => isset($movie['rating']) ? (float) $movie['rating'] : null,
            'imdbVotes' => null,
            'boxOffice' => null,
            'awards' => 'N/A',
        ];

        // Cache-first: the listing never triggers an OMDb call, the cast is filled by
        // the canonical row once the movie has been enriched (on selection).
        $canonical = MovieData::findByImdbIdAndSource($imdbId, MovieSource::Omdb);

        return [
            'id' => null,
            'title' => $title,
            'source' => MovieSource::Yts->value,
            'imdb_id' => $imdbId,
            'created_at' => $movie['date_uploaded'] ?? null,
            'selectable' => $torrent !== null,
            'data' => MovieData::mergedDisplay($canonical, $display, MovieSource::Yts),
        ];
    }

    /**
     * Torrent with the most seeds, then the smallest size. Torrents without seeds are
     * unusable: YTS is peer-to-peer only, there is no web-seed fallback.
     *
     * @param  list<array<string, mixed>>  $torrents
     * @return array<string, mixed>|null
     */
    private function bestTorrent(array $torrents): ?array
    {
        $available = array_values(array_filter(
            $torrents,
            fn(array $torrent): bool => (int) ($torrent['seeds'] ?? 0) > 0,
        ));

        if ($available === []) {
            return null;
        }

        usort($available, fn(array $a, array $b): int => [
            -(int) ($a['seeds'] ?? 0),
            (int) ($a['size_bytes'] ?? 0),
        ] <=> [
            -(int) ($b['seeds'] ?? 0),
            (int) ($b['size_bytes'] ?? 0),
        ]);

        return $available[0];
    }
}
