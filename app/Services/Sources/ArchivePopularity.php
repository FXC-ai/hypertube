<?php

namespace App\Services\Sources;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The popularity of the catalog: archive.org reports how many times each item has been
 * downloaded, and its search endpoint answers for many identifiers at once, so a batch
 * of a hundred movies is a single request.
 */
final class ArchivePopularity
{
    private const SEARCH_ENDPOINT = 'https://archive.org/advancedsearch.php';

    /**
     * archive.org answers a 200 response holding an error body once the query grows past
     * roughly 2000 characters, so the batches are sized on the length of the query.
     */
    private const MAX_QUERY_LENGTH = 1500;

    public function __construct(private readonly int $batchSize = 50)
    {
    }

    /**
     * The download counts of these items, keyed by identifier. Items archive.org does
     * not know are simply absent, and a failing request yields nothing instead of
     * breaking the import that asked for it.
     *
     * @param list<string> $identifiers
     * @return array<string, int>
     */
    public function fetch(array $identifiers): array
    {
        $counts = [];

        foreach ($this->batches($identifiers) as $batch) {
            $counts += $this->fetchBatch($batch);
        }

        return $counts;
    }

    /**
     * Consecutive identifiers grouped so that neither the batch size nor the length of
     * the resulting query is exceeded.
     *
     * @param list<string> $identifiers
     * @return Generator<list<string>>
     */
    private function batches(array $identifiers): Generator
    {
        $batch = [];
        // 'identifier:(' and the closing parenthesis are always part of the query.
        $length = 13;

        foreach ($identifiers as $identifier) {
            $added = strlen($identifier) + ($batch === [] ? 0 : 4);

            if ($batch !== [] && ($length + $added > self::MAX_QUERY_LENGTH || count($batch) >= $this->batchSize)) {
                yield $batch;

                $batch = [];
                $length = 13;
            }

            $batch[] = $identifier;
            $length += $added;
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * @param list<string> $batch
     * @return array<string, int>
     */
    private function fetchBatch(array $batch): array
    {
        $identifiers = array_values(array_filter(
            $batch,
            fn(string $identifier): bool => preg_match('/^[A-Za-z0-9._\-]+$/', $identifier) === 1,
        ));

        if ($identifiers === []) {
            return [];
        }

        try {
            $response = Http::timeout(30)->get(self::SEARCH_ENDPOINT, [
                'q' => 'identifier:('.implode(' OR ', $identifiers).')',
                // A repeated fl[] parameter is rejected by archive.org, a comma separated
                // list is what it answers.
                'fl' => 'identifier,downloads',
                'rows' => count($identifiers),
                'output' => 'json',
            ]);
        } catch (Throwable $e) {
            Log::warning('Archive.org popularity request failed: {message}', ['message' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Archive.org popularity query failed with status: {status}', [
                'status' => $response->status(),
            ]);

            return [];
        }

        // A rejected parameter is reported inside a 200 response, so the batch that got
        // through is the only one left to look at.
        $error = $response->json('error');

        if (is_string($error)) {
            Log::warning('Archive.org rejected the popularity query: {error}', ['error' => $error]);

            return [];
        }

        $counts = [];

        foreach ($response->json('response.docs') ?? [] as $document) {
            $identifier = $document['identifier'] ?? null;
            $downloads = $document['downloads'] ?? null;

            if (is_string($identifier) && is_numeric($downloads)) {
                $counts[$identifier] = (int) $downloads;
            }
        }

        return $counts;
    }
}
