<?php

namespace App\Models;

use App\Enums\MovieSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'imdb_id',
    'source',
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
            'imdbRating' => 'float',
        ];
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
                'source' => MovieSource::Omdb->value,
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
                'imdbRating' => isset($data['imdbRating']) && ($data['imdbRating'] != 'N/A') ? $data['imdbRating'] : null,
                'imdbVotes' => isset($data['imdbVotes']) && ($data['imdbVotes'] != 'N/A') ? $data['imdbVotes'] : null,
                'boxOffice' => isset($data['BoxOffice']) && ($data['BoxOffice'] != 'N/A') ? $data['BoxOffice'] : null,
                'release_date' => isset($data['Released']) && $data['Released'] != 'N/A' ? Carbon::parse($data['Released']) : null,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function displayArray(): array
    {
        return [
            'imdbID' => $this->imdb_id,
            'year' => $this->release_date?->format('Y') ?? 'N/A',
            'rated' => $this->rated,
            'released' => $this->release_date?->format('d M Y'),
            'runtime' => $this->runtime ? (string) $this->runtime . ' min' : 'N/A',
            'genre' => $this->genres ? implode(', ', $this->genres) : 'N/A',
            'director' => $this->director ?? 'N/A',
            'writer' => $this->writers ? implode(', ', $this->writers) : 'N/A',
            'actors' => $this->actors ? implode(', ', $this->actors) : 'N/A',
            'plot' => $this->plot ?? 'No plot available.',
            'language' => $this->language ? implode(', ', $this->language) : 'N/A',
            'poster' => $this->poster ?? null,
            'imdbRating' => $this->imdbRating,
            'imdbVotes' => $this->imdbVotes,
            'boxOffice' => $this->boxOffice,
            'awards' => is_array($this->awards) ? ($this->awards['text'] ?? 'N/A') : 'N/A',
        ];
    }
}
