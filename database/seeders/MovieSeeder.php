<?php

namespace Database\Seeders;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Database\Factories\MovieFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
    }
}
