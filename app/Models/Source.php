<?php

namespace App\Models;

use App\Enums\MovieSource;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source',
    'imdb_id',
    'title',
    'titles',
    'torrent_url',
])]
/**
 * A movie as a catalog offers it: the sources table is our search API, the `source`
 * column is what tells the catalogs apart.
 *
 * @property MovieSource $source
 * @property string $imdb_id
 * @property string $title
 * @property array<string, string>|null $titles
 * @property string|null $torrent_url
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source' => MovieSource::class,
            'titles' => 'array',
        ];
    }

    /**
     * The catalog entry kept when the same movie is listed by several sources: the oldest one.
     */
    public static function firstForImdbId(string $imdbId): ?self
    {
        return self::where('imdb_id', $imdbId)->orderBy('id')->first();
    }

    /**
     * @return HasOne<Movie, $this>
     */
    public function movie(): HasOne
    {
        return $this->hasOne(Movie::class, 'imdb_id', 'imdb_id');
    }
}
