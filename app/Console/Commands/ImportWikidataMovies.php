<?php

namespace App\Console\Commands;

use App\Models\Movie;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputOption;

#[Signature('import:wikidata-movies {--limit=1000000} {--dry-run} {--batch-size=1000}')]
#[Description('Import public domain films from Wikidata into the movies table')]
class ImportWikidataMovies extends Command
{
    protected function getQuery(int $limit): string
    {
        return sprintf(
            <<<"SPARQL"
            SELECT ?film ?imdbID ?internetArchiveID ?archivePage ?torrentURL ?titlesJson
            WHERE {
              {
                SELECT ?film ?imdbID ?internetArchiveID ?torrentURL
                       (CONCAT("{", GROUP_CONCAT(?langTitle; separator=", "), "}") AS ?titlesJson)
                WHERE {
                  ?film wdt:P724 ?internetArchiveID.
                  ?film wdt:P31/wdt:P279* wd:Q11424 .

                  ?film wdt:P345 ?imdbID .

                  # Récupération de tous les labels existants
                  ?film rdfs:label ?label .
                  BIND(LANG(?label) AS ?lang)

                  # On ne garde que les langues principales pour ne pas surcharger le JSON (ex: fr, en, es, de, it)
                  FILTER(?lang IN ("fr", "en", "es", "de", "it"))

                  # Construction de la paire clé-valeur JSON : "fr": "Mon Titre"
                  BIND(CONCAT('"', ?lang, '": "', REPLACE(STR(?label), '"', '\\"'), '"') AS ?langTitle)
                }
                GROUP BY ?film ?imdbID ?internetArchiveID ?torrentURL
              }

              BIND(URI(CONCAT("https://archive.org/download/", ?internetArchiveID, "/", ?internetArchiveID, "_archive.torrent")) AS ?torrentURL)
              BIND(URI(CONCAT("https://archive.org/details/", ?internetArchiveID)) AS ?archivePage)
            }
            LIMIT %d
            SPARQL,
            $limit,
        );
    }

    protected function getOptions(): array
    {
        return [
            ['limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of movies to import', 200],
            ['batch-size', null, InputOption::VALUE_OPTIONAL, 'Batch size for insert', 1000],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be imported without saving'],
        ];
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');

        $this->info('🎬 Importing public domain films from Wikidata...');
        $this->info("Limit: {$limit} | Batch: {$batchSize} | Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE'));

        $query = $this->getQuery($limit);

        try {
            $response = Http::timeout(120)->withHeaders([
                'Accept' => 'application/sparql-results+json',
                'User-Agent' => 'Hypertube Wikidata Importer (https://github.com/yourrepo)',
            ])->get('https://query.wikidata.org/sparql', [
                'query' => $query,
            ]);
        } catch (\Exception $e) {
            $this->error("❌ Failed to query Wikidata: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("❌ Wikidata query failed with status: {$response->status()}");
            $this->error("Response: {$response->body()}");

            return self::FAILURE;
        }

        $data = $response->json();
        $results = $data['results']['bindings'] ?? [];

        if (empty($results)) {
            $this->warn('⚠️ No results returned.');

            return self::SUCCESS;
        }

        $newMovies = [];
        $skipped = 0;
        $failed = 0;

        $movieCount = count($results);
        $this->info("Found $movieCount films. Processing...\n");

        foreach ($results as $index => $result) {
            $imdbId = $result['imdbID']['value'] ?? null;
            $internetArchiveId = $result['internetArchiveID']['value'] ?? null;
            $titlesJson = $result['titlesJson']['value'] ?? null;
            $torrentUrl = $result['torrentURL']['value'] ?? null;

            if (! $imdbId || ! $internetArchiveId) {
                $this->warn("⚠️ Skipping result #{$index}: missing imdb_id or internet_archive_id");
                $failed++;

                continue;
            }

            // Parse titles JSON
            $titles = null;
            $titlesEncoded = null;
            if ($titlesJson) {
                $decoded = json_decode($titlesJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $titles = $decoded;
                    $titlesEncoded = json_encode($titles);
                }
            }

            // Determine default title (prefer French, then English)
            $defaultTitle = null;
            if (is_array($titles) && isset($titles['fr'])) {
                $defaultTitle = $titles['fr'];
            } elseif (is_array($titles) && isset($titles['en'])) {
                $defaultTitle = $titles['en'];
            } elseif (is_array($titles) && ! empty($titles)) {
                $defaultTitle = array_values($titles)[0];
            }

            if ($dryRun) {
                $this->line("📝 Would import: {$imdbId} - " . ($defaultTitle ?? 'Unknown'));
                $skipped++;

                continue;
            }

            // Collect for batch insert
            $newMovies[] = [
                'title' => $defaultTitle,
                'titles' => $titlesEncoded,
                'imdb_id' => $imdbId,
                'filename' => null,
                'torrent_url' => $torrentUrl,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($newMovies)) {
            $this->info("\n📦 Inserting " . count($newMovies) . " movies in batches of {$batchSize}...");

            $batchInserted = 0;
            $batchTotal = 0;

            foreach (array_chunk($newMovies, $batchSize) as $chunkIndex => $batch) {
                $batchTotal++;
                // TODO: We ignore duplicate entries for the same movie, but we could store all the torrent variants
                $inserted = Movie::insertOrIgnore($batch);
                $batchInserted += $inserted;

                $this->line("  Batch {$batchTotal}: {$inserted} movies inserted");

                // Small delay between batches to avoid overwhelming the DB
                usleep(100000); // 100ms
            }

            $this->info("\n✅ Batch insert complete: {$batchInserted} movies inserted");
        }

        $this->newLine();
        $this->info("🎉 Import complete!");
        $this->line("   ✅ Imported:  " . count($newMovies));
        $this->line("   ⏭️  Skipped:  {$skipped}");
        $this->line("   ❌ Failed:    {$failed}");

        return self::SUCCESS;
    }
}
