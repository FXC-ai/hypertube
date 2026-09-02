<?php

namespace Database\Factories;

use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Movie>
 */
class MovieFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */

    public function definition(): array
    {
        $filename = fake()->unique()->slug(3) . '.mp4';

        return [
            'title' => fake()->sentence(3),
            'filename' => $filename,
            'filepath' => 'movies/' . $filename,
        ];
    }
}
