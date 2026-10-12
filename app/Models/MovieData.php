<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'movie_id',
    'source',
    'imdb_id',
    'release_date',
    'poster',
    'plot',
    'genres',
    'runtime',
    'actors',
    'director',
    'writers',
    'rated',
    'language',
    'imdbRating',
    'imdbVotes',
    'boxOffice',
    'awards',
])]
class MovieData extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'genres' => 'array',
            'actors' => 'array',
            'writers' => 'array',
            'language' => 'array',
            'awards' => 'array',
            'release_date' => 'date',
        ];
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public static function findByImdbId(string $imdbId): ?self
    {
        return self::where('imdb_id', $imdbId)->first();
    }

    public static function storeFromOmdb(string $imdbId, array $data): self
    {
        return static::updateOrCreate(
            ['imdb_id' => $imdbId],
            [
                'movie_id' => null,
                'source' => 'omdb',
                'poster' => $data['Poster'] ?? null,
                'plot' => $data['Plot'] ?? null,
                'genres' => isset($data['Genre']) ? explode(',', $data['Genre']) : null,
                'runtime' => isset($data['Runtime']) ? (int) preg_replace('/\D/', '', $data['Runtime']) : null,
                'actors' => isset($data['Actors']) ? explode(',', $data['Actors']) : null,
                'director' => $data['Director'] ?? null,
                'writers' => isset($data['Writer']) ? explode(',', $data['Writer']) : null,
                'rated' => $data['Rated'] ?? null,
                'language' => isset($data['Language']) ? explode(',', $data['Language']) : null,
                'awards' => isset($data['Awards']) ? ['text' => $data['Awards']] : null,
                'imdbRating' => ($data['imdbRating'] != 'N/A') ? $data['imdbRating'] : null,
                'imdbVotes' => ($data['imdbVotes'] != 'N/A') ? $data['imdbVotes'] : null,
                'boxOffice' => ($data['BoxOffice'] != 'N/A') ? $data['BoxOffice'] : null,
                'release_date' => $data['Released'] != 'N/A' ? \Carbon\Carbon::parse($data['Released']) : null,
            ],
        );
    }
}
