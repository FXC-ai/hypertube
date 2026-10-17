<?php

namespace App\Services\Media;

use App\Enums\DownloadStatus;
use App\Models\Movie;
use App\Services\Torrent\TorrentClient;
use Illuminate\Support\Facades\Storage;

final class MovieInputResolver
{
    public function __construct(private TorrentClient $torrentClient) {}

    public function resolve(Movie $movie): MovieInput
    {
        if ($movie->download_status === DownloadStatus::Downloading && $movie->download_id !== null && $movie->download_file_index !== null) {
            return new MovieInput(
                $this->torrentClient->fileUrl($movie->download_id, $movie->download_file_index),
                isStream: true,
            );
        }

        return new MovieInput(
            Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}"),
            isStream: false,
        );
    }
}
