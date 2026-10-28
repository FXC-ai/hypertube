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
    ) {
    }

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
                // $response = Http::timeout(30)->get('http://www.omdbapi.com/demo.aspx/', [
                'apikey' => $this->apiKey,
                // 'token' => "demo",
                'i' => $imdbId,
                'plot' => 'full',
                'r' => 'json',
            ]);

            if (!$response->successful()) {
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
     * Fetch data from OMDb if not already cached, store it, and return the canonical row.
     */
    public function enrichByImdbId(string $imdbId): ?MovieData
    {
        // Check if already cached
        $cached = MovieData::findByImdbId($imdbId);

        if ($cached !== null) {
            return $cached;
        }

        $data = $this->fetchByImdbId($imdbId);

        if ($data === null) {
            return null;
        }

        return MovieData::storeFromOmdb($imdbId, $data);
    }
}
