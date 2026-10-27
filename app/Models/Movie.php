<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Enums\MovieSource;
use App\Services\OmdbService;
use Database\Factories\MovieFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'imdb_id',
    'filename',
    'torrent_url',
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
 * The local state of a movie: what lives on this server (torrent, files, conversion).
 * Catalog information (titles, source) comes from the Source entries sharing its imdb_id.
 *
 * @property string|null $imdb_id
 * @property string|null $filename
 * @property string|null $torrent_url
 * @property ConversionStatus $conversion_status
 * @property DownloadStatus $download_status
 * @property-read string|null $title
 * @property-read MovieSource|null $source
 */
class Movie extends Model
{
    /** @use HasFactory<MovieFactory> */
    use HasFactory;

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderByDesc('created_at');
    }

    /**
     * Every catalog entry offering this movie, oldest first: the first one is the
     * source whose information was kept.
     *
     * @return HasMany<Source, $this>
     */
    public function sourceEntries(): HasMany
    {
        return $this->hasMany(Source::class, 'imdb_id', 'imdb_id')->orderBy('id');
    }

    /**
     * @return HasOne<MovieData, $this>
     */
    public function movieData(): HasOne
    {
        return $this->hasOne(MovieData::class, 'imdb_id', 'imdb_id');
    }

    public function sourceEntry(): ?Source
    {
        return $this->sourceEntries->first();
    }

    public function getTitleAttribute(): ?string
    {
        return $this->sourceEntry()?->title;
    }

    public function getSourceAttribute(): ?MovieSource
    {
        return $this->sourceEntry()?->source;
    }

    /**
     * Materialize a catalog entry as a local movie. A movie already known for this
     * imdb_id keeps its own torrent and downloaded file, whatever source asked.
     */
    public static function fromCatalogEntry(Source $entry): self
    {
        return self::firstOrCreate(
            ['imdb_id' => $entry->imdb_id],
            ['torrent_url' => $entry->torrent_url],
        );
    }

    public function getEnrichedData(): ?MovieData
    {
        if ($this->imdb_id === null) {
            return null;
        }

        return OmdbService::factory()->enrichByImdbId($this->imdb_id);
    }

    protected function casts(): array
    {
        return [
            'conversion_status' => ConversionStatus::class,
            'download_status' => DownloadStatus::class,
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
