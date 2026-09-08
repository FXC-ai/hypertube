<?php

namespace Database\Factories;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
        $filename = fake()->unique()->slug(3).'.mp4';
        /** @var ConversionStatus $status */
        $status = fake()->randomElement(ConversionStatus::cases());

        return [
            'title' => fake()->sentence(3),
            'filename' => $filename,
            'filepath' => '/storage/app/public/movies/pending/'.Str::uuid().'/'.$filename,
            ...$this->conversionAttributes($status),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Movie $movie): void {
            $movie->updateQuietly([
                'filepath' => "/storage/app/public/movies/{$movie->id}/{$movie->filename}",
            ]);
        });
    }

    public function withConversionStatus(ConversionStatus $status): static
    {
        return $this->state(fn (): array => $this->conversionAttributes($status));
    }

    /**
     * @return array{
     *     conversion_status: ConversionStatus,
     *     conversion_attempt: string|null,
     *     conversion_error: string|null,
     *     conversion_started_at: CarbonImmutable|null,
     *     conversion_playable_at: CarbonImmutable|null,
     *     conversion_completed_at: CarbonImmutable|null
     * }
     */
    private function conversionAttributes(ConversionStatus $status): array
    {
        if (in_array($status, [ConversionStatus::Pending, ConversionStatus::Queued], true)) {
            return [
                'conversion_status' => $status,
                'conversion_attempt' => null,
                'conversion_error' => null,
                'conversion_started_at' => null,
                'conversion_playable_at' => null,
                'conversion_completed_at' => null,
            ];
        }

        $startedAt = CarbonImmutable::instance(fake()->dateTimeBetween('-1 month', '-1 hour'));
        $playableAt = $startedAt->addSeconds(fake()->numberBetween(5, 1800));

        return match ($status) {
            ConversionStatus::Converting => [
                'conversion_status' => $status,
                'conversion_attempt' => (string) Str::uuid(),
                'conversion_error' => null,
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => null,
                'conversion_completed_at' => null,
            ],
            ConversionStatus::Playable => [
                'conversion_status' => $status,
                'conversion_attempt' => (string) Str::uuid(),
                'conversion_error' => null,
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => $playableAt,
                'conversion_completed_at' => null,
            ],
            ConversionStatus::Converted => [
                'conversion_status' => $status,
                'conversion_attempt' => (string) Str::uuid(),
                'conversion_error' => null,
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => $playableAt,
                'conversion_completed_at' => $playableAt->addSeconds(fake()->numberBetween(1, 3600)),
            ],
            ConversionStatus::Failed => [
                'conversion_status' => $status,
                'conversion_attempt' => (string) Str::uuid(),
                'conversion_error' => fake()->randomElement([
                    'ffprobe_failed',
                    'ffmpeg_failed',
                    'hls_output_not_playable',
                ]),
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => fake()->boolean() ? $playableAt : null,
                'conversion_completed_at' => null,
            ],
            default => unreachable(),
        };
    }
}
