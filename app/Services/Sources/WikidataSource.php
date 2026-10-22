<?php

namespace App\Services\Sources;

use App\Enums\MovieSource;
use App\Http\Resources\IndexMovieResource;
use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WikidataSource implements MovieSourceProvider
{
    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    public static function factory(): self
    {
        return new self;
    }

    public function source(): MovieSource
    {
        return MovieSource::Wikidata;
    }

    /**
     * The legal catalog is the database built by the Wikidata import, so querying this
     * source is a local query.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(Request $request, string $term, int $page, int $perPage): array
    {
        $query = Movie::query()->where('source', MovieSource::Wikidata->value);

        if ($term !== '') {
            $query->where('title', 'like', '%' . $term . '%');
        }

        $sort = in_array($request->input('sort'), ['created_at'], true) ? $request->input('sort') : 'created_at';
        $dir = in_array($request->input('dir'), ['asc', 'desc'], true) ? $request->input('dir') : 'desc';

        $paginated = $query->orderBy($sort, $dir)->orderBy('id', $dir)
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => IndexMovieResource::collection($paginated->items())->toArray($request),
            'total' => $paginated->total(),
        ];
    }

    public function import(int $limit, int $batchSize = 500, bool $dryRun = false): int
    {
        $entries = $this->fetch($limit);

        if ($dryRun) {
            return 0;
        }

        $inserted = 0;

        foreach (array_chunk(array_column($entries, 'movie'), $batchSize) as $batch) {
            $inserted += Movie::insertOrIgnore($batch);
            usleep(100000);
        }

        return $inserted;
    }

    /**
     * @return list<array{movie: array<string, mixed>, data: array<string, mixed>}>
     */
    public function fetch(int $limit): array
    {
        $response = Http::timeout(120)->withHeaders([
            'Accept' => 'application/sparql-results+json',
        ])->get(self::SPARQL_ENDPOINT, ['query' => $this->getQuery($limit)]);

        if (!$response->successful()) {
            throw new RuntimeException("Wikidata query failed with status: {$response->status()}");
        }

        $entries = [];

        foreach ($response->json()['results']['bindings'] ?? [] as $result) {
            $imdbId = $result['imdbID']['value'] ?? null;
            $internetArchiveId = $result['internetArchiveID']['value'] ?? null;

            if (!$imdbId || !$internetArchiveId) {
                continue;
            }

            $titles = $this->decodeTitles($result['titlesJson']['value'] ?? null);
            $defaultTitle = $titles['fr'] ?? $titles['en'] ?? (array_values($titles)[0] ?? null);

            if ($defaultTitle === null) {
                continue;
            }

            $entries[] = [
                'movie' => [
                    'title' => $defaultTitle,
                    'titles' => $titles === [] ? null : json_encode($titles),
                    'source' => MovieSource::Wikidata->value,
                    'source_reference' => 'archiveorg:' . $internetArchiveId,
                    'imdb_id' => $imdbId,
                    'filename' => null,
                    'torrent_url' => $result['torrentURL']['value'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                'data' => [],
            ];
        }

        return $entries;
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

    private function getQuery(int $limit): string
    {
        return sprintf(
            <<<'SPARQL'
            SELECT ?film ?imdbID ?internetArchiveID ?archivePage ?torrentURL ?titlesJson
            WHERE {
              {
                SELECT ?film ?imdbID ?internetArchiveID ?torrentURL
                       (CONCAT("{", GROUP_CONCAT(?langTitle; separator=", "), "}") AS ?titlesJson)
                WHERE {
                  ?film wdt:P724 ?internetArchiveID.
                  ?film wdt:P31/wdt:P279* wd:Q11424 .

                  ?film wdt:P345 ?imdbID .

                  # Récupération de tous les labels existants
                  ?film rdfs:label ?label .
                  BIND(LANG(?label) AS ?lang)

                  # On ne garde que les langues principales pour ne pas surcharger le JSON (ex: fr, en, es, de, it)
                  FILTER(?lang IN ("fr", "en", "es", "de", "it"))

                  # Construction de la paire clé-valeur JSON : "fr": "Mon Titre"
                  BIND(CONCAT('"', ?lang, '": "', REPLACE(STR(?label), '"', '\"'), '"') AS ?langTitle)
                }
                GROUP BY ?film ?imdbID ?internetArchiveID ?torrentURL
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
