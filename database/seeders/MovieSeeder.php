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
            ->withCatalogEntry('Mkv test with multiple subtitles', MovieSource::Whitelist)
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
                'torrent_url' => 'magnet:?xt=urn:btih:79816060ea56d56f2a2148cd45705511079f9bca&dn=TPB.AFK.2013.720p.h264-SimonKlose&tr=udp%3A%2F%2Ftracker.openbittorrent.com%3A80&tr=udp%3A%2F%2Fopen.demonii.com%3A1337&tr=udp%3A%2F%2Ftracker.coppersurfer.tk%3A6969&tr=udp%3A%2F%2Fexodus.desync.com%3A6969',
                'imdb_id' => 'tt2608732',
            ]);

        $bigBunny = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->withCatalogEntry('Big Buck Bunny', MovieSource::Whitelist)
            ->create([
                'download_status' => DownloadStatus::Pending,
                'filename' => null,
                'torrent_url' => 'magnet:?xt=urn:btih:dd8255ecdc7ca55fb0bbf81323d87062db1f6d1c&dn=Big+Buck+Bunny&tr=udp%3A%2F%2Fexplodie.org%3A6969&tr=udp%3A%2F%2Ftracker.coppersurfer.tk%3A6969&tr=udp%3A%2F%2Ftracker.empire-js.us%3A1337&tr=udp%3A%2F%2Ftracker.leechers-paradise.org%3A6969&tr=udp%3A%2F%2Ftracker.opentrackr.org%3A1337&tr=wss%3A%2F%2Ftracker.btorrent.xyz&tr=wss%3A%2F%2Ftracker.fastcast.nz&tr=wss%3A%2F%2Ftracker.openwebtorrent.com&ws=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2F&xs=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2Fbig-buck-bunny.torrent',
            ]);

        MovieData::factory()
            ->forImdbId($bigBunny->imdb_id)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 11,
                'genres' => ['Test'],
                'director' => 'webtorrent',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        $cosmosLandromat = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->withCatalogEntry('Cosmos Landromat', MovieSource::Whitelist)
            ->create([
                'download_status' => DownloadStatus::Pending,
                'filename' => null,
                'torrent_url' => 'magnet:?xt=urn:btih:c9e15763f722f23e98a29decdfae341b98d53056&dn=Cosmos+Laundromat&tr=udp%3A%2F%2Fexplodie.org%3A6969&tr=udp%3A%2F%2Ftracker.coppersurfer.tk%3A6969&tr=udp%3A%2F%2Ftracker.empire-js.us%3A1337&tr=udp%3A%2F%2Ftracker.leechers-paradise.org%3A6969&tr=udp%3A%2F%2Ftracker.opentrackr.org%3A1337&tr=wss%3A%2F%2Ftracker.btorrent.xyz&tr=wss%3A%2F%2Ftracker.fastcast.nz&tr=wss%3A%2F%2Ftracker.openwebtorrent.com&ws=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2F&xs=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2Fcosmos-laundromat.torrent',
            ]);

        MovieData::factory()
            ->forImdbId($cosmosLandromat->imdb_id)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 12,
                'genres' => ['Test'],
                'director' => 'webtorrent',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        $sintel = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->withCatalogEntry('Sintel', MovieSource::Whitelist)
            ->create([
                'download_status' => DownloadStatus::Pending,
                'filename' => null,
                'torrent_url' => 'magnet:?xt=urn:btih:08ada5a7a6183aae1e09d831df6748d566095a10&dn=Sintel&tr=udp%3A%2F%2Fexplodie.org%3A6969&tr=udp%3A%2F%2Ftracker.coppersurfer.tk%3A6969&tr=udp%3A%2F%2Ftracker.empire-js.us%3A1337&tr=udp%3A%2F%2Ftracker.leechers-paradise.org%3A6969&tr=udp%3A%2F%2Ftracker.opentrackr.org%3A1337&tr=wss%3A%2F%2Ftracker.btorrent.xyz&tr=wss%3A%2F%2Ftracker.fastcast.nz&tr=wss%3A%2F%2Ftracker.openwebtorrent.com&ws=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2F&xs=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2Fsintel.torrent',
            ]);

        MovieData::factory()
            ->forImdbId($sintel->imdb_id)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 15,
                'genres' => ['Test'],
                'director' => 'webtorrent',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        // Movies the catalog offers but that this server has not loaded yet.
        /*         $publicDomain = [];

        foreach (['Night of the Living Dead', 'Nosferatu', 'Metropolis'] as $title) {
            $publicDomain[] = Source::factory()
                ->forSource(MovieSource::Wikidata)
                ->create(['title' => $title]);
        } */

        // A whitelisted short film whose archive.org item is real, so the popularity
        // fetch has something to report in this development database.
        /*         Source::factory()
            ->forSource(MovieSource::Whitelist)
            ->create([
                'title' => 'Cops',
                'internet_archive_id' => 'Cops',
                'torrent_url' => 'https://archive.org/download/Cops/Cops_archive.torrent',
            ]); */

        // The whitelist curates a movie the public domain catalog offers too.
        /*         Source::factory()
            ->forSource(MovieSource::Whitelist)
            ->duplicateOf($publicDomain[0])
            ->create(); */
    }
}
