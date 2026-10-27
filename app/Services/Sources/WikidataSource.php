<?php

namespace App\Services\Sources;

use App\Data\MovieSearch;
use App\Enums\MovieSource;
use App\Enums\WatchedFilter;
use App\Models\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The public domain catalog built by the Wikidata import. Both this source and the
 * curated whitelist live in the sources table: the table is the API, the `source`
 * column is what tells the catalogs apart.
 */
final class WikidataSource implements MovieSourceProvider
{
    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    public function __construct(private readonly MovieSource $source)
    {
    }

    public static function factory(MovieSource $source): self
    {
        return new self($source);
    }

    public function source(): MovieSource
    {
        return $this->source;
    }

    /**
     * @return array{items: list<Source>, total: int}
     */
    public function search(MovieSearch $criteria): array
    {
        $query = Source::query()->where('source', $this->source->value);

        if ($criteria->term !== '') {
            $query->where('title', 'like', '%'.$criteria->term.'%');
        }

        $this->restrictToGenres($query, $criteria);
        $this->restrictToYears($query, $criteria);
        $this->restrictToRatings($query, $criteria);
        $this->restrictToWatched($query, $criteria);

        // The merged listing slices a window built from every source, so this one has
        // to hand over enough rows to cover the requested page. An entry missing the
        // criterion is always ranked last, whatever the direction.
        $column = $criteria->sort->column();

        $window = $query
            ->orderByRaw($column.' is null')
            ->orderBy($column, $criteria->direction)
            ->orderBy('id', $criteria->direction)
            ->paginate($criteria->windowSize(), ['*'], 'page', 1);

        return [
            'items' => array_values($window->getCollection()->all()),
            'total' => $window->total(),
        ];
    }

    /**
     * Genres are stored as pipe delimited lowercase labels, so a criterion is matched
     * as a whole label instead of inside another one.
     *
     * @param Builder<Source> $query
     */
    private function restrictToGenres(Builder $query, MovieSearch $criteria): void
    {
        if ($criteria->genre === null) {
            return;
        }

        $query->where('genres', 'like', $criteria->genrePattern());
    }

    /**
     * @param Builder<Source> $query
     */
    private function restrictToYears(Builder $query, MovieSearch $criteria): void
    {
        if ($criteria->yearFrom !== null) {
            $query->where('release_year', '>=', $criteria->yearFrom);
        }

        if ($criteria->yearTo !== null) {
            $query->where('release_year', '<=', $criteria->yearTo);
        }
    }

    /**
     * @param Builder<Source> $query
     */
    private function restrictToRatings(Builder $query, MovieSearch $criteria): void
    {
        if ($criteria->ratingFrom !== null) {
            $query->where('imdb_rating', '>=', $criteria->ratingFrom);
        }

        if ($criteria->ratingTo !== null) {
            $query->where('imdb_rating', '<=', $criteria->ratingTo);
        }
    }

    /**
     * The catalog lives in this database, so the watched restriction of the user can
     * be applied here instead of after the sources have been merged.
     *
     * @param Builder<Source> $query
     */
    private function restrictToWatched(Builder $query, MovieSearch $criteria): void
    {
        if ($criteria->watched === WatchedFilter::All) {
            return;
        }

        $isWatched = fn(QueryBuilder $watched): QueryBuilder => $watched
            ->select(DB::raw(1))
            ->from('movies')
            ->whereColumn('movies.imdb_id', 'sources.imdb_id')
            ->whereExists(fn(QueryBuilder $viewer): QueryBuilder => $viewer
                ->select(DB::raw(1))
                ->from('watched_movies')
                ->whereColumn('watched_movies.movie_id', 'movies.id')
                ->where('watched_movies.user_id', $criteria->userId));

        $criteria->watched === WatchedFilter::Watched
            ? $query->whereExists($isWatched)
            : $query->whereNotExists($isWatched);
    }

    public function entry(string $imdbId): ?Source
    {
        return Source::query()
            ->where('source', $this->source->value)
            ->where('imdb_id', $imdbId)
            ->first();
    }

    /**
     * Refresh the catalog rows of this source, and duplicate the whitelisted movies
     * so the curated source exposes them too.
     *
     * @param list<string> $whitelist
     */
    public function import(int $limit, array $whitelist = [], int $batchSize = 500, bool $dryRun = false): int
    {
        $rows = $this->fetch($limit);
        $byImdbId = collect($rows)->keyBy('imdb_id');

        foreach ($whitelist as $imdbId) {
            $entry = $byImdbId->get($imdbId);

            if ($entry !== null) {
                $rows[] = [...$entry, 'source' => MovieSource::Whitelist->value];
            }
        }

        if ($dryRun) {
            return count($rows);
        }

        $inserted = 0;

        foreach (array_chunk($rows, max(1, $batchSize)) as $batch) {
            $inserted += Source::insertOrIgnore($batch);
        }

        return $inserted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(int $limit): array
    {
        try {
            $response = Http::timeout(120)->withHeaders([
                'Accept' => 'application/sparql-results+json',
                'User-Agent' => 'Hypertube Wikidata Importer (https://github.com/yourrepo)',
            ])->get(self::SPARQL_ENDPOINT, ['query' => $this->getQuery($limit)]);
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to query Wikidata: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Wikidata query failed with status: {$response->status()}");
        }

        $rows = [];

        foreach ($response->json()['results']['bindings'] ?? [] as $result) {
            $imdbId = $result['imdbID']['value'] ?? null;
            $internetArchiveId = $result['internetArchiveID']['value'] ?? null;

            if (! $imdbId || ! $internetArchiveId) {
                continue;
            }

            $titles = $this->decodeTitles($result['titlesJson']['value'] ?? null);
            $defaultTitle = $titles['fr'] ?? $titles['en'] ?? (array_values($titles)[0] ?? null);

            if ($defaultTitle === null) {
                continue;
            }

            $releaseYear = (int) ($result['releaseYear']['value'] ?? 0);

            $rows[] = [
                'source' => $this->source->value,
                'imdb_id' => $imdbId,
                'title' => $defaultTitle,
                'titles' => $titles === [] ? null : json_encode($titles),
                'torrent_url' => $result['torrentURL']['value'] ?? null,
                'release_year' => $releaseYear > 0 ? $releaseYear : null,
                // Wikidata knows nothing about the IMDb grade: OMDb fills it later.
                'imdb_rating' => null,
                'genres' => Source::storedGenres(explode('|', $result['genresCsv']['value'] ?? '')),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function decodeTitles(?string $titlesJson): array
    {
        if ($titlesJson === null) {
            return [];
        }

        $decoded = json_decode($titlesJson, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }

    /**
     * The catalog rows carry the criteria the listing is filtered and sorted on, so
     * the year and the genres come from Wikidata with the movie itself. The IMDb grade
     * is not known there and is filled later from OMDb.
     */
    private function getQuery(int $limit): string
    {
        return sprintf(
            <<<'SPARQL'
            SELECT ?film ?imdbID ?internetArchiveID ?archivePage ?torrentURL ?titlesJson ?releaseYear ?genresCsv
            WHERE {
              {
                SELECT ?film ?imdbID ?internetArchiveID
                       (CONCAT("{", GROUP_CONCAT(?langTitle; separator=", "), "}") AS ?titlesJson)
                       (MIN(?yearStr) AS ?releaseYear)
                       (GROUP_CONCAT(DISTINCT ?genreStr; separator="|") AS ?genresCsv)
                WHERE {
                  ?film wdt:P724 ?internetArchiveID.
                  ?film wdt:P31/wdt:P279* wd:Q11424 .

                  ?film wdt:P345 ?imdbID .

                  # Publication year, empty when Wikidata does not know it: the aggregate
                  # has to stay bound, or the film would vanish from the projection.
                  OPTIONAL { ?film wdt:P577 ?publicationDate . }
                  BIND(IF(BOUND(?publicationDate), SUBSTR(STR(?publicationDate), 1, 4), "") AS ?yearStr)

                  # English genre labels, empty when the film has none or none in english.
                  OPTIONAL {
                    ?film wdt:P136 ?genre .
                    ?genre rdfs:label ?genreLabel .
                    FILTER(LANG(?genreLabel) = "en")
                  }
                  BIND(IF(BOUND(?genreLabel), LCASE(STR(?genreLabel)), "") AS ?genreStr)

                  # Récupération de tous les labels existants
                  ?film rdfs:label ?label .
                  BIND(LANG(?label) AS ?lang)

                  # On ne garde que les langues principales pour ne pas surcharger le JSON (ex: fr, en, es, de, it)
                  FILTER(?lang IN ("fr", "en", "es", "de", "it"))

                  # Construction de la paire clé-valeur JSON : "fr": "Mon Titre"
                  BIND(CONCAT('"', ?lang, '": "', REPLACE(STR(?label), '"', '\"'), '"') AS ?langTitle)
                }
                GROUP BY ?film ?imdbID ?internetArchiveID
              }

              BIND(URI(CONCAT("https://archive.org/download/", ?internetArchiveID, "/", ?internetArchiveID, "_archive.torrent")) AS ?torrentURL)
              BIND(URI(CONCAT("https://archive.org/details/", ?internetArchiveID)) AS ?archivePage)
            }
            LIMIT %d
            SPARQL,
            $limit,
        );
    }
}
