<?php

namespace Database\Factories;

use App\Models\MovieData;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovieData>
 */
class MovieDataFactory extends Factory
{
    public function definition(): array
    {
        return [
            'movie_id' => null,
            'source' => 'omdb',
            'imdb_id' => 'tt' . fake()->unique()->numerify('#######'),
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

    public function forMovie(Movie $movie): static
    {
        return $this->state(fn(): array => [
            'movie_id' => $movie->getKey(),
            'imdb_id' => $movie->imdb_id,
        ]);
    }

    public function withoutPoster(): static
    {
        return $this->state(fn(): array => [
            'poster' => null,
        ]);
    }
}
