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

        // Downloaded through the Client Torrent on "Prepare video" (public domain, archive.org).
        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'His Girl Friday (1940)',
                'filename' => 'his_girl_friday.mp4',
                'torrent_url' => 'https://archive.org/download/his_girl_friday/his_girl_friday_archive.torrent',
            ]);
    }
}
