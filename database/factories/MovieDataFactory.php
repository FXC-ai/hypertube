<?php

namespace Database\Factories;

use App\Enums\MovieSource;
use App\Models\MovieData;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovieData>
 */
class MovieDataFactory extends Factory
{
    /**
     * Define the model's default state (canonical OMDb metadata).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movie_id' => null,
            'source' => MovieSource::Omdb->value,
            'imdb_id' => 'tt' . fake()->numerify('######'),
            'release_date' => fake()->date(),
            'poster' => fake()->imageUrl(),
            'plot' => fake()->paragraph(),
            'genres' => [fake()->word()],
            'runtime' => fake()->numberBetween(60, 180),
            'actors' => [fake()->name()],
            'director' => fake()->name(),
            'writers' => [fake()->name()],
            'rated' => fake()->randomElement(['G', 'PG', 'R']),
            'language' => ['en'],
            'imdbRating' => fake()->randomFloat(1, 1, 10),
            'imdbVotes' => fake()->numberBetween(100, 100000),
            'boxOffice' => null,
            'awards' => null,
        ];
    }

    /**
     * Metadata provided by the YTS catalog: no cast, no awards, no IMDb rating.
     */
    public function yts(): static
    {
        return $this->state(fn(): array => [
            'source' => MovieSource::Yts->value,
            'release_date' => fake()->year() . '-01-01',
            'director' => null,
            'writers' => null,
            'actors' => null,
            'awards' => null,
            'imdbVotes' => null,
            'boxOffice' => null,
        ]);
    }
}
