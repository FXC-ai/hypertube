<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;



class MovieController extends Controller
{
    public function hlsManifest(Movie $movie): BinaryFileResponse
    {
        Log::channel("my_debug")->debug("metadatas = ", ["ca fonctionne"]);

        $path = Storage::disk('public')->path("movies/{$movie->id}/hls/index.m3u8");



        $ffprobe = FFProbe::create([
            'ffprobe.binaries' => 'C:\\ffmpeg\\bin\\ffprobe.exe',
        ]);
        $duration = $ffprobe
            ->format($path)
            ->get('duration');

        Log::channel("my_debug")->debug("metadatas = ", [$duration]);


        return response()->file($path, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, no-cache, must-revalidate', // Le manifeste utilise no-cache afin que le lecteur puisse le redemander. C’est particulièrement utile plus tard lorsque le manifeste sera produit progressivement.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function hlsSegment(
        Movie $movie,
        string $segment,
    ): BinaryFileResponse {
        $path = Storage::disk('public')->path("movies/{$segment}");

        return response()->file($path, [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'private, max-age=31536000, immutable', // Les segments sont immuables : segment_00001.ts ne doit jamais changer après sa création. Ils peuvent donc être conservés longtemps dans le cache privé du navigateur.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }


    public function watch(Movie $movie): BinaryFileResponse
    {
        $filename = Storage::disk('public')->path($movie->filename);

        $path = Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}");
        Log::channel("my_debug")->debug("path = ", [$path]);

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
        return Inertia::render(
            'movies/show',
            [
                "movie" => $movie,
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
