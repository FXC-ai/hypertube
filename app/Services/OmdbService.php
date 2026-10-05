<?php

namespace App\Services;

use App\Models\MovieData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OmdbService
{
    public function __construct(
        #[\SensitiveParameter] private ?string $apiKey
    ) {}

    public static function factory(): self
    {
        return new self(config('services.omdb.key'));
    }

    public function fetchByImdbId(string $imdbId): ?array
    {
        if ($this->apiKey == null) {
            Log::warning('OMDb API key not configured, skipping enrichment for {imdb_id}', ['imdb_id' => $imdbId]);
            return null;
        }

        if (empty($imdbId)) {
            return null;
        }

        try {
            $response = Http::timeout(30)->get('http://www.omdbapi.com/', [
                'apikey' => $this->apiKey,
                'i' => $imdbId,
                'plot' => 'full',
                'r' => 'json',
            ]);

            if (! $response->successful()) {
                Log::error('OMDb API request failed for {imdb_id}: status {status}', [
                    'imdb_id' => $imdbId,
                    'status' => $response->status(),
                ]);
                return null;
            }
            $data = $response->json();

            if ($data['Response'] !== 'True') {
                Log::error('OMDb returned error for {imdb_id}: {error}', [
                    'imdb_id' => $imdbId,
                    'error' => $data['Error'] ?? 'Unknown',
                ]);

                return null;
            }

            return $data;
        } catch (Throwable $e) {
            Log::error('OMDb API exception for {imdb_id}: {message}', [
                'imdb_id' => $imdbId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fetch data from OMDb if not already cached, store it, and return it.
     */
    public function enrichByImdbId(string $imdbId): ?array
    {
        // Check if already cached
        $cached = MovieData::findByImdbId($imdbId);

        if ($cached !== null) {
            return $this->normalizeCachedData($cached);
        }

        // Fetch from OMDb
        $data = $this->fetchByImdbId($imdbId);

        if ($data === null) {
            return null;
        }

        // Store for next time
        MovieData::storeFromOmdb($imdbId, $data);

        return $data;
    }

    private function normalizeCachedData(MovieData $data): array
    {
        return [
            'imdbID' => $data->imdb_id,
            'title' => $data->movie?->title ?? 'Unknown',
            'year' => $data->release_date?->format('Y') ?? 'N/A',
            'rated' => $data->rated,
            'released' => $data->release_date?->format('d M Y'),
            'runtime' => $data->runtime ? (string) $data->runtime . ' min' : 'N/A',
            'genre' => $data->genres ? implode(', ', $data->genres) : 'N/A',
            'director' => $data->director ?? 'N/A',
            'writer' => $data->writers ? implode(', ', $data->writers) : 'N/A',
            'actors' => $data->actors ? implode(', ', $data->actors) : 'N/A',
            'plot' => $data->plot ?? 'No plot available.',
            'language' => $data->language ? implode(', ', $data->language) : 'N/A',
            'poster' => $data->poster ?? null,
            'awards' => is_array($data->awards) ? ($data->awards['text'] ?? 'N/A') : 'N/A',
        ];
    }

    /**
     * Update a MovieData record with a movie_id reference.
     */
    public function linkMovie(MovieData $movieData, int $movieId): void
    {
        $movieData->movie_id = $movieId;
        $movieData->save();
    }
}
