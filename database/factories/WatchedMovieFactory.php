<?php

namespace Database\Factories;

use App\Models\Movie;
use App\Models\User;
use App\Models\WatchedMovie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchedMovie>
 */
class WatchedMovieFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movie_id' => Movie::factory(),
            'user_id' => User::factory(),
            'watched_at' => CarbonImmutable::instance(fake()->dateTimeBetween('-1 month', 'now')),
        ];
    }
}
