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

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Mkv test evaluation',
                'download_status' => DownloadStatus::Pending,
                'filename' => null,
                'titles' => '{"en":"Mkv de test evaluation "}',
                'torrent_url' => 'https://itorrents.org/torrent/79816060EA56D56F2A2148CD45705511079F9BCA.torrent',
                'imdb_id' => 'tt2608732'
            ]);
    }
}
