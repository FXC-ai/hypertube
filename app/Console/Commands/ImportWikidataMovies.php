<?php

namespace App\Console\Commands;

use App\Services\Sources\WikidataSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

#[Signature('import:wikidata-movies {--limit=1000000} {--dry-run} {--batch-size=1000}')]
#[Description('Import public domain films from Wikidata into the movies table')]
class ImportWikidataMovies extends Command
{
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

        $source = WikidataSource::factory();

        try {
            $entries = $source->fetch($limit);
        } catch (\Exception $e) {
            $this->error("❌ Failed to query Wikidata: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($entries === []) {
            $this->warn('⚠️ No results returned.');

            return self::SUCCESS;
        }

        $this->info('Found ' . count($entries) . ' films. Processing...');

        if ($dryRun) {
            foreach ($entries as $entry) {
                $this->line('📝 Would import: ' . $entry['movie']['imdb_id'] . ' - ' . $entry['movie']['title']);
            }

            $this->newLine();
            $this->info('🎉 Dry run complete: ' . count($entries) . ' movies would be imported');

            return self::SUCCESS;
        }

        $inserted = $source->import($limit, $batchSize, false);

        $this->newLine();
        $this->info('🎉 Import complete!');
        $this->line('   ✅ Fetched:   ' . count($entries));
        $this->line("   📦 Inserted:  {$inserted}");

        return self::SUCCESS;
    }
}
