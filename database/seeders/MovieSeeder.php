<?php

namespace Database\Seeders;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\MovieData;
use App\Models\Source;
use Illuminate\Database\Seeder;

class MovieSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // A movie already downloaded on this server, with its metadata.
        $downloaded = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->withCatalogEntry('Mkv test with multiple subtitles')
            ->create([
                'download_status' => DownloadStatus::Completed,
                'filename' => 'test.mkv',
                'torrent_url' => null,
            ]);

        MovieData::factory()
            ->forImdbId($downloaded->imdb_id)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 2,
                'genres' => ['Test'],
                'director' => 'chatgpt',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        // A movie the catalog offers, ready to be downloaded.
        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->withCatalogEntry('Mkv test evaluation', MovieSource::Whitelist)
            ->create([
                'download_status' => DownloadStatus::Pending,
                'filename' => null,
                'torrent_url' => 'https://itorrents.org/torrent/79816060EA56D56F2A2148CD45705511079F9BCA.torrent',
                'imdb_id' => 'tt2608732',
            ]);

        // Movies the catalog offers but that this server has not loaded yet.
        $publicDomain = [];

        foreach (['Night of the Living Dead', 'Nosferatu', 'Metropolis'] as $title) {
            $publicDomain[] = Source::factory()
                ->forSource(MovieSource::Wikidata)
                ->create(['title' => $title]);
        }

        Source::factory()
            ->forSource(MovieSource::Whitelist)
            ->create(['title' => 'Cops']);

        // The whitelist curates a movie the public domain catalog offers too.
        Source::factory()
            ->forSource(MovieSource::Whitelist)
            ->duplicateOf($publicDomain[0])
            ->create();
    }
}
