<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Sources\ArchivePopularity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Input\InputOption;

#[Signature('archive:fetch-popularity {--limit=} {--batch=50}')]
#[Description('Fill the download counts archive.org reports, the criterion the listing is sorted on by default')]
class FetchArchivePopularity extends Command
{
    protected function getOptions(): array
    {
        return [
            ['limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of catalog entries to look up, every missing one when empty', 0],
            ['batch', null, InputOption::VALUE_OPTIONAL, 'Identifiers asked for in a single archive.org request, fewer when the query would grow too long', 50],
        ];
    }

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?? 0);
        $batch = max(1, (int) ($this->option('batch') ?? 50));

        $backfilled = $this->backfillIdentifiers();

        if ($backfilled > 0) {
            $this->line('   Identifiers read back from the torrent links: '.$backfilled);
        }

        $entries = Source::query()
            ->whereNull('popularity')
            ->whereNotNull('internet_archive_id')
            ->orderBy('id');

        if ($limit > 0) {
            $entries->limit($limit);
        }

        $identifiers = array_values($entries->pluck('internet_archive_id')->unique()->all());

        $this->info('📊 Fetching the download counts of '.count($identifiers).' archive.org items...');

        $counts = (new ArchivePopularity($batch))->fetch($identifiers);

        foreach ($counts as $identifier => $downloads) {
            Source::storePopularity($identifier, $downloads);
        }

        $this->line('   Popularity filled: '.count($counts).' items out of '.count($identifiers));

        return self::SUCCESS;
    }

    /**
     * The catalog rows imported before the identifier was kept still carry it inside
     * their torrent link.
     */
    private function backfillIdentifiers(): int
    {
        $backfilled = 0;

        Source::query()
            ->whereNull('internet_archive_id')
            ->whereNotNull('torrent_url')
            ->orderBy('id')
            ->chunkById(500, function (Collection $entries) use (&$backfilled): void {
                foreach ($entries as $entry) {
                    $identifier = Source::identifierFromTorrentUrl($entry->torrent_url);

                    if ($identifier !== null) {
                        $entry->internet_archive_id = $identifier;
                        $entry->save();

                        $backfilled++;
                    }
                }
            });

        return $backfilled;
    }
}
