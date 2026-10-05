<?php

namespace App\Console\Commands;

use App\Models\Movie;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputOption;

#[Signature('import:wikidata-movies {--limit=1000000} {--dry-run}')]
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
            ['limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of movies to import', 100],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be imported without saving'],
        ];
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('🎬 Importing public domain films from Wikidata...');
        $this->info("Limit: {$limit} | Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE'));

        // Get already imported imdb_ids for comparison
        $existingImdbIds = Movie::query()->whereNotNull('imdb_id')->pluck('imdb_id')->toArray();

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

        $imported = 0;
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
            if ($titlesJson) {
                $decoded = json_decode($titlesJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $titles = $decoded;
                }
            }

            // Determine default title (prefer French, then English)
            $defaultTitle = null;
            if (isset($titles['fr'])) {
                $defaultTitle = $titles['fr'];
            } elseif (isset($titles['en'])) {
                $defaultTitle = $titles['en'];
            } elseif (! empty($titles)) {
                $defaultTitle = array_values($titles)[0];
            }

            // Check if already imported
            if (in_array($imdbId, $existingImdbIds)) {
                $this->line("⏭️  Skipped (already exists): {$imdbId}");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("📝 Would import: {$imdbId} - " . ($defaultTitle ?? 'Unknown'));
                $imported++;

                continue;
            }

            try {
                \App\Models\Movie::query()->create([
                    'title' => $defaultTitle,
                    'titles' => $titles,
                    'imdb_id' => $imdbId,
                    'filename' => null,
                    'torrent_url' => $torrentUrl,
                ]);

                $imported++;
                $this->line("✅ Imported: {$imdbId} - " . ($defaultTitle ?? 'Unknown'));
            } catch (\Exception $e) {
                Log::error('Wikidata import failed', [
                    'imdb_id' => $imdbId,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
                $this->line("❌ Failed: {$imdbId} - {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("🎉 Import complete!");
        $this->line("   ✅ Imported:  {$imported}");
        $this->line("   ⏭️  Skipped:  {$skipped}");
        $this->line("   ❌ Failed:    {$failed}");

        return self::SUCCESS;
    }
}
