<?php

namespace Database\Seeders;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Illuminate\Database\Seeder;

class MovieSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test1.mkv']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test2.mkv']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test3.mp4']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test4.mkv']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test5.mkv']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(
                [
                    'title' => 'Film pour tester les mkv',
                    'titles' => '{"en":"Candy","de":"Candy","es":"Candy","fr":"Candy","it":"Candy e il suo pazzo mondo"}',
                    'torrent_url' => 'https://yts.vg/movie/90443/download/720p/web/x264/DFC687CD6EDA2A0EA84A93516439B255EBBB02E1',
                    'download_status' => 'pending'
                ]
            );
    }
}
