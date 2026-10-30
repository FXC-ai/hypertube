<?php

namespace App\Http\Controllers;

use App\Enums\MovieSource;
use App\Http\Requests\ImportMovieRequest;
use App\Models\Movie;
use App\Models\Source;
use App\Rules\TorrentUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MovieImportController extends Controller
{
    public function __construct(
        private MovieConversionController $conversionController,
    ) {}

    public function store(ImportMovieRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $torrentUrl = TorrentUrl::withoutInternalLinks((string) $data['torrent_url']);
        $imdbId = 'manual-'.substr(hash('sha256', $torrentUrl), 0, 32);
        $title = is_string($data['title'] ?? null) && $data['title'] !== ''
            ? mb_substr($data['title'], 0, 255)
            : $this->titleFromTorrent($torrentUrl, $imdbId);

        $movie = DB::transaction(function () use ($torrentUrl, $imdbId, $title): Movie {
            Source::updateOrCreate(
                ['source' => MovieSource::Whitelist->value, 'imdb_id' => $imdbId],
                ['title' => $title, 'torrent_url' => $torrentUrl],
            );

            $movie = Movie::firstOrCreate(['imdb_id' => $imdbId], ['torrent_url' => $torrentUrl]);

            if ($movie->torrent_url !== $torrentUrl) {
                $movie->update(['torrent_url' => $torrentUrl]);
            }

            return $movie;
        }, attempts: 3);

        $this->conversionController->prepare($movie);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Video added.')]);

        return to_route('movies.show', $movie);
    }

    private function titleFromTorrent(string $torrentUrl, string $fallback): string
    {
        $queryString = parse_url($torrentUrl, PHP_URL_QUERY);

        parse_str(is_string($queryString) ? $queryString : '', $query);
        $downloadName = $query['dn'] ?? null;

        if (is_string($downloadName) && $downloadName !== '') {
            return mb_substr(urldecode($downloadName), 0, 255);
        }

        $path = parse_url($torrentUrl, PHP_URL_PATH);

        if (is_string($path) && $path !== '') {
            return mb_substr(pathinfo(basename($path), PATHINFO_FILENAME), 0, 255);
        }

        return mb_substr($fallback, 0, 255);
    }
}
