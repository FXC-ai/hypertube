<?php

namespace App\Console\Commands;

use App\Enums\MovieSource;
use App\Models\Source;
use App\Services\OmdbService;
use App\Services\Sources\WikidataSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

const WHITELIST = [
    "tt42576262",
];

#[Signature('import:wikidata-movies {--limit=1000000} {--dry-run} {--batch-size=1000} {--enrich=0}')]
#[Description('Import public domain films from Wikidata into the sources catalog table')]
class ImportWikidataMovies extends Command
{
    protected function getOptions(): array
    {
        return [
            ['limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of movies to import', 200],
            ['batch-size', null, InputOption::VALUE_OPTIONAL, 'Batch size for insert', 1000],
            ['dry-run', null, InputOption::VALUE_NONE, 'Show what would be imported without saving'],
            ['enrich', null, InputOption::VALUE_OPTIONAL, 'Fetch OMDb metadata for that many entries missing their IMDb grade', 0],
        ];
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');

        $this->info('🎬 Importing public domain films from Wikidata...');
        $this->info("Limit: {$limit} | Batch: {$batchSize} | Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE'));

        $source = WikidataSource::factory(MovieSource::Wikidata);

        try {
            $entryCount = $source->import($limit, WHITELIST, $batchSize, $dryRun);
        } catch (Throwable $e) {
            $this->error("❌ Failed to query Wikidata: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($entryCount === 0) {
            $this->warn('⚠️ No results returned.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('🎉 Import complete!');
        $this->line('   '.($dryRun ? 'Would insert' : 'Inserted').':   '.$entryCount);
        $this->line('   Whitelisted: '.count(WHITELIST));

        $enrichLimit = (int) ($this->option('enrich') ?? 0);

        if ($enrichLimit > 0 && ! $dryRun) {
            $enriched = $this->enrichWithOmdbMetadata($enrichLimit);

            $this->line('   OMDb enriched: '.$enriched.'/'.$enrichLimit.' (IMDb grade used by the filters)');
        }

        return self::SUCCESS;
    }

    /**
     * Wikidata has no IMDb grade, so the entries missing one get their metadata from
     * OMDb: the rating filter and the thumbnail score then have something to show.
     */
    private function enrichWithOmdbMetadata(int $limit): int
    {
        $omdb = OmdbService::factory();
        $enriched = 0;

        $entries = Source::query()
            ->where('source', MovieSource::Wikidata->value)
            ->whereNull('imdb_rating')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($entries as $entry) {
            if ($omdb->enrichByImdbId($entry->imdb_id) !== null) {
                $enriched++;
            }
        }

        return $enriched;
    }
}
