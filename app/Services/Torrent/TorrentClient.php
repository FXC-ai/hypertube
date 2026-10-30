<?php

namespace App\Services\Torrent;

use App\Exceptions\TorrentClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        return $this->send(
            operation: __FUNCTION__,
            request: fn (PendingRequest $http): Response => $http->post('/torrents/inspect', [
                'torrentUrl' => $torrentUrl,
            ]),
        )->json();
    }

    /**
     * Starts a download in the background and returns its id.
     *
     * @param  list<int>  $fileIndexes
     */
    public function startDownload(string $torrentUrl, string $outputDirectory, array $fileIndexes, string $infoHash): string
    {
        return $this->send(
            operation: __FUNCTION__,
            request: fn (PendingRequest $http): Response => $http->post('/downloads', [
                'torrentUrl' => $torrentUrl,
                'outputDir' => $outputDirectory,
                'fileIndexes' => $fileIndexes,
                'expectedInfoHash' => $infoHash,
            ]),
            context: [
                'selected_file_count' => count($fileIndexes),
                'info_hash' => $infoHash,
            ],
        )->json('id');
    }

    /**
     * Current state of a download, or null when the service does not know the id
     * (it keeps its jobs in memory, so a restart forgets them).
     *
     * @return array{
     *     id?: string,
     *     status: string,
     *     error?: string|null,
     *     piecesCompleted?: int,
     *     totalPieces?: int,
     * }|null
     */
    public function status(string $downloadId): ?array
    {
        $response = $this->send(
            operation: __FUNCTION__,
            request: fn (PendingRequest $http): Response => $http->get('/downloads/'.rawurlencode($downloadId)),
            context: [
                'download_id' => $downloadId,
            ],
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
     * @param  array<string, mixed>  $context
     */
    private function send(
        string $operation,
        callable $request,
        array $context = [],
        bool $allowNotFound = false,
    ): Response {

        $logContext = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'operation' => $operation,
            ...$context,
        ];

        if ($operation !== 'status') {
            Log::channel('my_debug')->debug(
                "Une requête est envoyée au client torrent.\n".json_encode(
                    $logContext,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );
        }

        try {
            $response = $request($this->client());
        } catch (ConnectionException $exception) {
            $torrentClientException = new TorrentClientException(
                'Client Torrent unreachable: '.$exception->getMessage(),
                previous: $exception,
            );

            Log::channel('my_debug')->error(
                "La connexion au client torrent a échoué.\n".json_encode(
                    [
                        ...$logContext,
                        'exception' => [
                            'type' => $torrentClientException::class,
                            'message' => $torrentClientException->getMessage(),
                            'file' => $torrentClientException->getFile(),
                            'line' => $torrentClientException->getLine(),
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            throw $torrentClientException;
        }

        if ($response->failed() && ! ($allowNotFound && $response->notFound())) {
            $responseError = $response->json('error');

            if (! is_string($responseError)) {
                $responseError = $response->body();
            }

            $torrentClientException = new TorrentClientException(
                "Client Torrent answered {$response->status()}: {$responseError}",
            );

            Log::channel('my_debug')->error(
                "Le client torrent a retourné une réponse en erreur.\n".json_encode(
                    [
                        ...$logContext,
                        'http_status' => $response->status(),
                        'response_error' => mb_substr((string) $responseError, 0, 2000),
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            throw $torrentClientException;
        }

        if ($operation !== 'status') {
            Log::channel('my_debug')->debug(
                "La requête vers le client torrent est terminée.\n".json_encode(
                    [
                        ...$logContext,
                        'http_status' => $response->status(),
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())->acceptJson()->timeout(60);
        $sharedSecret = config('media.torrent_client_shared_secret');

        if (is_string($sharedSecret) && $sharedSecret !== '') {
            $request = $request->withHeaders(['x-torrent-client-secret' => $sharedSecret]);
        }

        return $request;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('media.torrent_client_url'), '/');
    }
}
