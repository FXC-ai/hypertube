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
                'torrent_url' => 'https://webtor.io/79816060ea56d56f2a2148cd45705511079f9bca.torrent?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhdWQiOiJ0b3JyZW50LWZpbGUiLCJleHAiOjE3OTMxMTI3NjksImlhdCI6MTc5MzA5MTE2OSwic3ViIjoiNzk4MTYwNjBlYTU2ZDU2ZjJhMjE0OGNkNDU3MDU1MTEwNzlmOWJjYSJ9.M98_BMO4afakzBYC_10381ucNTUnG_F90hn3Jc9ZPvw',
                //'torrent_url' => 'https://itorrents.org/torrent/79816060EA56D56F2A2148CD45705511079F9BCA.torrent',
                'imdb_id' => 'tt2608732'
            ]);
    }
}
