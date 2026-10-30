<?php

namespace App\Models;

use App\Enums\MovieSource;
use Carbon\Carbon;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'source',
    'imdb_id',
    'title',
    'titles',
    'torrent_url',
    'internet_archive_id',
    'popularity',
    'release_year',
    'imdb_rating',
    'genres',
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
 * @property string|null $internet_archive_id
 * @property int|null $popularity
 * @property int|null $release_year
 * @property float|null $imdb_rating
 * @property string|null $genres
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
            'imdb_rating' => 'float',
            'popularity' => 'integer',
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
     * The archive.org item a torrent link points to: the popularity of a movie is the
     * download count of that item.
     */
    public static function identifierFromTorrentUrl(?string $torrentUrl): ?string
    {
        if ($torrentUrl === null) {
            return null;
        }

        return preg_match('#archive\.org/download/([^/]+)/#', $torrentUrl, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * The archive.org item of this entry, read from the link for the catalog rows
     * imported before the identifier was kept.
     */
    public function archiveIdentifier(): ?string
    {
        return $this->internet_archive_id ?? self::identifierFromTorrentUrl($this->torrent_url);
    }

    /**
     * The download count archive.org reports for an item, on every catalog entry
     * offering it.
     */
    public static function storePopularity(string $identifier, int $downloads): void
    {
        self::query()
            ->where('internet_archive_id', $identifier)
            ->update(['popularity' => $downloads]);
    }

    /**
     * How a genre list is stored for filtering: lowercase labels, deduplicated, each of
     * them wrapped in pipes so a criterion is matched whole.
     *
     * @param  list<string>  $labels
     */
    public static function storedGenres(array $labels): ?string
    {
        $genres = collect($labels)
            ->map(fn (string $label): string => mb_strtolower(trim($label)))
            ->filter(fn (string $label): bool => $label !== '')
            ->unique()
            ->sort()
            ->values();

        return $genres->isEmpty() ? null : '|'.$genres->implode('|').'|';
    }

    /**
     * Every genre the given catalogs actually offer, so the filter never proposes a
     * criterion that matches nothing.
     *
     * @param  list<string>  $sources
     * @return list<string>
     */
    public static function genreOptions(array $sources): array
    {
        return Cache::remember(
            'catalog-genre-options-'.implode(',', $sources),
            Carbon::now()->addMinutes(10),
            function () use ($sources): array {
                /** @var Collection<int, string> $stored */
                $stored = self::query()
                    ->whereIn('source', $sources)
                    ->whereNotNull('genres')
                    ->distinct()
                    ->pluck('genres');

                return array_values($stored
                    ->flatMap(fn (string $genres): array => array_filter(
                        explode('|', $genres),
                        fn (string $label): bool => $label !== '',
                    ))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all());
            },
        );
    }

    /**
     * The genre labels of this entry, ready to be shown.
     *
     * @return list<string>
     */
    public function genreLabels(): array
    {
        return array_values(array_filter(
            explode('|', (string) $this->genres),
            fn (string $label): bool => $label !== '',
        ));
    }

    /**
     * Keeps the filterable copy of the IMDb grade and of the production year in step
     * with the canonical metadata, for the catalog entries of every source.
     */
    public static function syncMetadata(string $imdbId, ?float $rating, ?int $releaseYear): void
    {
        self::query()->where('imdb_id', $imdbId)->update(['imdb_rating' => $rating]);

        if ($releaseYear !== null) {
            self::query()
                ->where('imdb_id', $imdbId)
                ->whereNull('release_year')
                ->update(['release_year' => $releaseYear]);
        }
    }

    /**
     * @return HasOne<Movie, $this>
     */
    public function movie(): HasOne
    {
        return $this->hasOne(Movie::class, 'imdb_id', 'imdb_id');
    }
}
