<?php

namespace Database\Seeders;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Models\Movie;
use App\Models\MovieData;
use Illuminate\Database\Seeder;

class MovieSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $testMkv = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Mkv test with multiple subtitles',
                'download_status' => DownloadStatus::Completed,
                'filename' => 'test.mkv',
                'titles' => '{"en":"Mkv test"}',
                'torrent_url' => null,
            ]);

        MovieData::factory()
            ->forMovie($testMkv)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 2,
                'genres' => ['Test'],
                'director' => 'chatgpt',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        $testevaluation = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Mkv test evaluation',
                'download_status' => DownloadStatus::Pending,
                'filename' => null,
                'titles' => '{"en":"Mkv de test evaluation "}',
                'torrent_url' => 'https://itorrents.org/torrent/79816060EA56D56F2A2148CD45705511079F9BCA.torrent',
            ]);

        MovieData::factory()
            ->forMovie($testevaluation)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 2,
                'genres' => ['Test'],
                'director' => 'evaluation',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        /*         Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Sintel',
                'titles' => '{"en":"Sintel"}',
                'torrent_url' => 'https://webtorrent.io/torrents/sintel.torrent',
                "imdb_id" => "tt1727587"
            ]);

        $bigBunnyBuck = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Big Bunny Buck',
                'titles' => '{"en":"Big Bunny Buck"}',
                'torrent_url' => 'https://webtorrent.io/torrents/big-buck-bunny.torrent',
            ]);

        MovieData::factory()
            ->forMovie($bigBunnyBuck)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 12,
                'genres' => ['Test'],
                'director' => 'Blender',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        $cosmosLandromat = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Cosmos Landromat',
                'titles' => '{"en":"Cosmos Landromat"}',
                'torrent_url' => 'https://webtorrent.io/torrents/cosmos-laundromat.torrent',
            ]);

        MovieData::factory()
            ->forMovie($cosmosLandromat)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 12,
                'genres' => ['Test'],
                'director' => 'Blender',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        $tearsOfSteal = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Tears of Steel',
                'titles' => '{"en":"Tears of Steel"}',
                'torrent_url' => 'https://webtorrent.io/torrents/tears-of-steel.torrent',
            ]);

        MovieData::factory()
            ->forMovie($tearsOfSteal)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 12,
                'genres' => ['Test'],
                'director' => 'Blender',
                'language' => ['English'],
                'imdbRating' => 0,
            ]); */
    }
}
