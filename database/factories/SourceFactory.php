<?php

namespace Database\Factories;

use App\Enums\MovieSource;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * Define the model's default state: a public domain catalog entry.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $internetArchiveId = fake()->unique()->slug(2);

        return [
            'source' => MovieSource::Wikidata->value,
            'imdb_id' => 'tt'.fake()->unique()->numerify('######'),
            'title' => fake()->sentence(3),
            'titles' => [
                'fr' => fake()->sentence(3),
                'en' => fake()->sentence(3),
            ],
            'torrent_url' => "https://archive.org/download/{$internetArchiveId}/{$internetArchiveId}_archive.torrent",
            'internet_archive_id' => $internetArchiveId,
            'release_year' => fake()->numberBetween(1880, 2020),
            'genres' => Source::storedGenres([fake()->randomElement([
                'drama film',
                'comedy film',
                'horror film',
                'western film',
                'documentary film',
            ])]),
        ];
    }

    public function forSource(MovieSource $source): static
    {
        return $this->state(fn (): array => ['source' => $source->value]);
    }

    /**
     * The same movie listed by a second source, sharing its imdb_id and title.
     */
    public function duplicateOf(Source $entry): static
    {
        return $this->state(fn (): array => [
            'imdb_id' => $entry->imdb_id,
            'title' => $entry->title,
            'torrent_url' => $entry->torrent_url,
        ]);
    }
}
