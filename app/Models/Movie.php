<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Enums\MovieSource;
use App\Services\OmdbService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'source',
    'source_reference',
    'titles',
    'filename',
    'torrent_url',
    'seeds',
    'peers',
    'torrent_quality',
    'torrent_size_bytes',
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
 * @property-read MovieSource $source
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

    public function getEnrichedData(): ?array
    {
        if ($this->imdb_id === null) {
            return null;
        }

        $source = $this->source ?? MovieSource::Wikidata;
        $sourceRow = MovieData::findByImdbIdAndSource($this->imdb_id, $source);
        $canonical = MovieData::findByImdbIdAndSource($this->imdb_id, MovieSource::Omdb);

        if ($canonical === null) {
            $omdbService = OmdbService::factory();
            $canonical = $omdbService->enrichByImdbId($this->imdb_id);

            if ($canonical !== null && $canonical->movie_id === null) {
                $omdbService->linkMovie($canonical, $this->id);
            }
        }

        if ($sourceRow !== null && $sourceRow->movie_id === null) {
            $sourceRow->movie_id = $this->id;
            $sourceRow->save();
        }

        return MovieData::mergedDisplay($canonical, $sourceRow?->displayArray(), $source);
    }

    protected function casts(): array
    {
        return [
            'source' => MovieSource::class,
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
