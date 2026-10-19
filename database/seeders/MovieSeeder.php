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
            );
    }
}
