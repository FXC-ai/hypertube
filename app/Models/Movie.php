<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use App\Services\OmdbService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\DownloadStatus;

#[Fillable([
    'title',
    'titles',
    'filename',
    'torrent_url',
    'imdb_id',
    'conversion_status',
    'conversion_attempt',
    'conversion_error',
    'conversion_started_at',
    'conversion_playable_at',
    'conversion_completed_at',
    'download_status',
    'download_id',
    'download_file_index',
    'download_error',
])]

/**
 * @property-read string|null $title
 * @property-read array<string, string>|null $titles
 */
class Movie extends Model
{
    use HasFactory;

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderByDesc('created_at');
    }

    public function movieData()
    {
        return $this->hasOne(MovieData::class);
    }

    public function watchedMovies(): HasMany
    {
        return $this->hasMany(WatchedMovie::class);
    }

    public function getEnrichedData(): ?array
    {
        if ($this->imdb_id == null) {
            return null;
        }

        $omdbService = OmdbService::factory();
        $enriched = $omdbService->enrichByImdbId($this->imdb_id);

        // Link the movie_data row to this movie if it was just created
        if ($enriched !== null) {
            $movieData = MovieData::findByImdbId($this->imdb_id);
            if ($movieData && $movieData->movie_id === null) {
                $omdbService->linkMovie($movieData, $this->id);
            }
        } else {
            // TODO: Log
        }

        return $enriched;
    }

    protected function casts(): array
    {
        return [
            'conversion_status' => ConversionStatus::class,
            'download_status' => DownloadStatus::class,
            'titles' => 'array',
            'conversion_started_at' => 'datetime',
            'conversion_playable_at' => 'datetime',
            'conversion_completed_at' => 'datetime',
        ];
    }

    public function isPlayable(): bool
    {
        return in_array($this->conversion_status, [
            ConversionStatus::Playable,
            ConversionStatus::Converted,
        ], true);
    }
}
