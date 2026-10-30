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
     * Define the model's default state: the canonical metadata of a movie.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => MovieSource::Omdb->value,
            'imdb_id' => 'tt'.fake()->unique()->numerify('#######'),
            'release_date' => fake()->dateTimeBetween('-100 years', 'now'),
            'poster' => fake()->imageUrl(600, 900, 'movies'),
            'plot' => fake()->paragraph(),
            'genres' => fake()->randomElements([
                'Action',
                'Comedy',
                'Drama',
                'Horror',
                'Romance',
                'Science Fiction',
                'Thriller',
            ], fake()->numberBetween(1, 3)),
            'runtime' => fake()->numberBetween(60, 210),
            'actors' => [
                fake()->name(),
                fake()->name(),
                fake()->name(),
            ],
            'director' => fake()->name(),
            'producer' => fake()->name(),
            'writers' => [
                fake()->name(),
                fake()->name(),
            ],
            'rated' => fake()->randomElement([
                'G',
                'PG',
                'PG-13',
                'R',
                'Not Rated',
            ]),
            'language' => fake()->randomElements([
                'English',
                'French',
                'German',
                'Italian',
                'Spanish',
            ], fake()->numberBetween(1, 2)),
            'imdbRating' => fake()->randomFloat(1, 1, 10),
            'imdbVotes' => fake()->numberBetween(100, 2_000_000),
            'boxOffice' => fake()->randomFloat(2, 10_000, 500_000_000),
            'awards' => [
                'text' => fake()->sentence(),
            ],
        ];
    }

    /**
     * The metadata of a movie the catalog already offers.
     */
    public function forImdbId(string $imdbId): static
    {
        return $this->state(fn (): array => ['imdb_id' => $imdbId]);
    }

    public function withoutPoster(): static
    {
        return $this->state(fn (): array => [
            'poster' => null,
        ]);
    }
}
