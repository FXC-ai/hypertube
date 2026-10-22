<?php

namespace Database\Seeders;

use App\Enums\ConversionStatus;
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
                'runtime' => 11,
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

        $sintel = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Sintel',
                'titles' => '{"en":"Sintel"}',
                'torrent_url' => 'https://webtorrent.io/torrents/sintel.torrent',
            ]);

        MovieData::factory()
            ->forMovie($sintel)
            ->create([
                'release_date' => '2000-01-01',
                'runtime' => 15,
                'genres' => ['Test'],
                'director' => 'Blender',
                'language' => ['English'],
                'imdbRating' => 0,
            ]);

        $tearsOfSteal = Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Sintel',
                'titles' => '{"en":"Sintel"}',
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
            ]);


        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Night of the Living Dead',
                'titles' => '{"en":"Night of the Living Dead"}',
                'torrent_url' => 'https://archive.org/download/Night.Of.The.Living.Dead_1080p/Night.Of.The.Living.Dead_1080p_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Manos: The Hands of Fate',
                'titles' => '{"en":"Manos: The Hands of Fate"}',
                'torrent_url' => 'https://archive.org/download/ManosTheHandsofFate/ManosTheHandsofFate_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'The Flying Deuces',
                'titles' => '{"en":"The Flying Deuces"}',
                'torrent_url' => 'https://archive.org/download/TheFlyingDeuces/TheFlyingDeuces_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'The Terror',
                'titles' => '{"en":"The Terror"}',
                'torrent_url' => 'https://archive.org/download/TheTerror/TheTerror_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Horror Express',
                'titles' => '{"en":"Horror Express"}',
                'torrent_url' => 'https://archive.org/download/horror_express_ipod/horror_express_ipod_archive.torrent',
            ]);


        /*         Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(
                [
                    'title' => 'Double Indemnity - mkv - archive.org',
                    'titles' => '{"en":"Double Indemnity","de":"Double Indemnity","es":"Double Indemnity","fr":"Double Indemnity","it":"Double Indemnity"}',
                    'torrent_url' => 'https://archive.org/download/Double.Indemnity.1944.720p.BrRip.x265.HEVCBay.com.mkv/Double.Indemnity.1944.720p.BrRip.x265.HEVCBay.com.mkv_archive.torrent',
                    'download_status' => 'pending'
                ]
            );

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(
                [
                    'title' => 'the keep (15.65 Go) - mkv - archive.org',
                    'titles' => '{"en":"the keep","de":"the keep","es":"the keep","fr":"the keep","it":"the keep"}',
                    'torrent_url' => 'https://archive.org/download/the-keep-v-1.1.mkv/the-keep-v-1.1.mkv_archive.torrent',
                    'download_status' => 'pending'
                ]
            );

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(
                [
                    'title' => 'Top Gun Maverick - mkv - archive.org',
                    'titles' => '{"en":"Top Gun Maverick","de":"Top Gun Maverick","es":"Top Gun Maverick","fr":"Top Gun Maverick","it":"Top Gun Maverick"}',
                    'torrent_url' => 'https://archive.org/download/the-blue-max-1966/the-blue-max-1966_archive.torrent',
                    'download_status' => 'pending'
                ]
            );

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(
                [
                    'title' => 'Source - mkv - YTS',
                    'titles' => '{"en":"Candy","de":"Candy","es":"Candy","fr":"Candy","it":"Candy e il suo pazzo mondo"}',
                    'torrent_url' => 'https://yts.vg/movie/90443/download/720p/web/x264/DFC687CD6EDA2A0EA84A93516439B255EBBB02E1',
                    'download_status' => 'pending'
                ]
            ); */
    }
}
