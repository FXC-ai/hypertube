<?php

namespace App\Services\Torrent;

use App\Exceptions\TorrentClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the Client Torrent service (torrent-client/API.md).
 */
final class TorrentClient
{
    /**
     * Lists the files of a .torrent without downloading them.
     *
     * @return array{infoHash: string, mainVideoIndex: int|null, files: list<array{index: int, path: string, fileName: string, length: int, kind: string, suggested: bool}>}
     */
    public function inspect(string $torrentUrl): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http->post('/torrents/inspect', [
            'torrentUrl' => $torrentUrl,
        ]))->json();
    }

    /**
     * Starts a download in the background and returns its id.
     *
     * @param  list<int>  $fileIndexes
     */
    public function startDownload(string $torrentUrl, string $outputDirectory, array $fileIndexes, string $infoHash): string
    {
        return $this->send(fn (PendingRequest $http): Response => $http->post('/downloads', [
            'torrentUrl' => $torrentUrl,
            'outputDir' => $outputDirectory,
            'fileIndexes' => $fileIndexes,
            'expectedInfoHash' => $infoHash,
        ]))->json('id');
    }

    /**
     * Current state of a download, or null when the service does not know the id
     * (it keeps its jobs in memory, so a restart forgets them).
     *
     * @return array{status: string, error: string|null}|null
     */
    public function status(string $downloadId): ?array
    {
        $response = $this->send(
            fn (PendingRequest $http): Response => $http->get('/downloads/'.rawurlencode($downloadId)),
            allowNotFound: true,
        );

        return $response->notFound() ? null : $response->json();
    }

    /**
     * Streaming URL of one chosen file, readable by ffprobe/ffmpeg while it downloads.
     */
    public function fileUrl(string $downloadId, int $fileIndex): string
    {
        return $this->baseUrl().'/downloads/'.rawurlencode($downloadId).'/files/'.$fileIndex;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request, bool $allowNotFound = false): Response
    {
        try {
            $response = $request(Http::baseUrl($this->baseUrl())->acceptJson()->timeout(60));
        } catch (ConnectionException $exception) {
            throw new TorrentClientException('Client Torrent unreachable: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed() && ! ($allowNotFound && $response->notFound())) {
            throw new TorrentClientException(
                "Client Torrent answered {$response->status()}: ".($response->json('error') ?? $response->body()),
            );
        }

        return $response;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('media.torrent_client_url'), '/');
    }
}
