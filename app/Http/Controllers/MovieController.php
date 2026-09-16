<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;



class MovieController extends Controller
{

    /*     public function __construct()
    {
        Log::channel("my_debug")->debug("\n", ["MovieController constructed"]);
    } */

    public function hlsManifest(Movie $movie, string $conversion_attempt): BinaryFileResponse
    {
        Log::channel("my_debug")->debug("\n", ["hlsManifest :"]);

        if ($conversion_attempt != $movie->conversion_attempt) {
            throw new Exception("Conversion atttempt does not exists");
        }

        $path = Storage::disk('public')->path("movies/{$movie->id}/hls/{$movie->conversion_attempt}/index.m3u8");

        Log::channel("my_debug")->debug("\n", ['path = ', $path]);


        return response()->file($path, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, no-cache, must-revalidate', // Le manifeste utilise no-cache afin que le lecteur puisse le redemander. C’est particulièrement utile plus tard lorsque le manifeste sera produit progressivement.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function hlsSegment(Movie $movie, string $conversion_attempt, string $segment): BinaryFileResponse
    {

        Log::channel("my_debug")->debug("hlsSegment ", ["called"]);


        if ($conversion_attempt != $movie->conversion_attempt) {
            throw new Exception("Conversion atttempt does not exists");
        }

        $path = Storage::disk('public')->path("movies/{$movie->id}/hls/{$movie->conversion_attempt}/{$segment}");

        Log::channel("my_debug")->debug("hls segment = ", [$path]);

        $mimeType = match (pathinfo($segment, PATHINFO_EXTENSION)) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'vtt' => 'text/vtt; charset=UTF-8',
            'ts' => 'video/mp2t',
            default => 'application/octet-stream',
        };

        $cacheControl = match (pathinfo($segment, PATHINFO_EXTENSION)) {
            'm3u8' => 'no-cache, must-revalidate',
            'vtt' => 'private, max-age=31536000, immutable',
            'ts' => 'private, max-age=31536000, immutable',
            default => 'no-cache, must-revalidate',
        };

        Log::channel("my_debug")->debug("mimeType = ", [$mimeType]);

        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Cache-Control' => $cacheControl, // Les segments sont immuables : segment_00001.ts ne doit jamais changer après sa création. Ils peuvent donc être conservés longtemps dans le cache privé du navigateur.
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
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Movie $movie): Response
    {
        Log::channel("my_debug")->debug("MovieController : ", ["show"]);
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
                ]
            ]
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Movie $movie)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Movie $movie)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Movie $movie)
    {
        //
    }
}
