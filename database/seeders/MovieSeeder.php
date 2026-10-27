<?php

namespace Database\Seeders;

use App\Enums\ConversionStatus;
use App\Enums\MovieSource;
use App\Models\Movie;
use App\Models\Source;
use Illuminate\Database\Seeder;

class MovieSeeder extends Seeder
{
    /**
     * Movies already downloaded on this server, and the catalog entry they come from.
     */
    private const LOADED = [
        'test1.mkv' => 'Night of the Living Dead',
        'test2.mkv' => 'Nosferatu',
        'test3.mp4' => 'The General',
        'test4.mkv' => 'Metropolis',
        'test5.mkv' => 'The Kid',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $entries = [];

        foreach (self::LOADED as $filename => $title) {
            $movie = Movie::factory()
                ->withConversionStatus(ConversionStatus::Pending)
                ->create(['filename' => $filename]);

            $entries[] = Source::factory()
                ->forSource(MovieSource::Wikidata)
                ->create(['title' => $title, 'imdb_id' => $movie->imdb_id]);
        }

        // The whitelist curates some of the same movies, plus one of its own.
        foreach ([0, 2] as $index) {
            Source::factory()
                ->forSource(MovieSource::Whitelist)
                ->duplicateOf($entries[$index])
                ->create();
        }

        Source::factory()
            ->forSource(MovieSource::Whitelist)
            ->create(['title' => 'Cops']);

        // Movies the catalog offers but that this server has not loaded yet.
        Source::factory()
            ->forSource(MovieSource::Wikidata)
            ->create(['title' => 'M']);

        Source::factory()
            ->forSource(MovieSource::Wikidata)
            ->create(['title' => 'Safety Last!']);
    }
}
