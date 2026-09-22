<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;



class MovieController extends Controller
{

    /*     public function __construct()
    {
        Log::channel("my_debug")->debug("\n", ["MovieController constructed"]);
    } */

    public function hlsManifest(Movie $movie, string $conversion_attempt): HttpResponse
    {
        Log::channel("my_debug")->debug("\n", ["hlsManifest :"]);

        if ($conversion_attempt != $movie->conversion_attempt) {
            throw new Exception("Conversion atttempt does not exists");
        }

        $path = Storage::disk('public')->path("movies/{$movie->id}/hls/{$movie->conversion_attempt}/index.m3u8");

        Log::channel("my_debug")->debug("\n", ['path = ', $path]);

        $content = file_get_contents($path);

        abort_if($content === false, 404);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Accept-Ranges' => 'none',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function hlsSegment(
        Movie $movie,
        string $conversion_attempt,
        string $segment,
    ): HttpResponse|BinaryFileResponse {

        Log::channel("my_debug")->debug("hlsSegment ", ["called"]);

        if ($conversion_attempt != $movie->conversion_attempt) {
            throw new Exception("Conversion atttempt does not exists");
        }

        $path = Storage::disk('public')->path("movies/{$movie->id}/hls/{$movie->conversion_attempt}/{$segment}");

        Log::channel("my_debug")->debug("hls segment = ", [$path]);

        $extension = strtolower(pathinfo($segment, PATHINFO_EXTENSION));

        if ($extension === 'm3u8') {
            $content = file_get_contents($path);

            abort_if($content === false, 404);

            return response($content, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Content-Length' => (string) strlen($content),
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Accept-Ranges' => 'none',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $mimeType = match ($extension) {
            'vtt' => 'text/vtt; charset=UTF-8',
            'ts' => 'video/mp2t',
            default => 'application/octet-stream',
        };

        $cacheControl = match ($extension) {
            'vtt', 'ts' => 'private, max-age=31536000, immutable',
            default => 'no-cache, must-revalidate',
        };

        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }


    public function watch(Movie $movie): BinaryFileResponse
    {
        $filename = Storage::disk('public')->path($movie->filename);

        $path = Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}");

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Movie $movie): InertiaResponse
    {
        // Log::channel("my_debug")->debug("MovieController : ", ["show", Auth::getUser()->toArray()["preferredlanguage"]]);
        return Inertia::render(
            'movies/show',
            [
                'moviePageData' => [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'filename' => $movie->filename,
                    'conversion_attempt' => $movie->conversion_attempt,
                    'conversion_status' => $movie->conversion_status->value,
                    'conversion_error' => $movie->conversion_status === ConversionStatus::Failed ? $movie->conversion_error : null,
                    'playable' => $movie->isPlayable(),
                    'preferredlanguage' => Auth::getUser()->toArray()["preferredlanguage"]
                ]

            ]
        );
    }
}
