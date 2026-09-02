<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MovieController extends Controller
{
    public function watch(Movie $movie)
    {
        Log::channel("my_debug")->debug("stream = ", ["appel"]);

        $path = Storage::disk('public')->path('movies/test.mp4');
        Log::channel("my_debug")->debug("path = ", [$path]);

        $exist = Storage::disk('public')->exists('movies/test.mp4');
        Log::channel("my_debug")->debug("exist = ", [$exist]);

        $size = Storage::disk('public')->size('movies/test.mp4');
        Log::channel("my_debug")->debug("size = ", [$size]);

        $stream = function () use ($path) {
            $stream = fopen($path, 'rb');
            while (!feof($stream)) {
                echo fread($stream, 1024 * 8);
                flush();
            }
            fclose($stream);
        };
        Log::channel("my_debug")->debug("stream = ", [$stream]);




        return response()->stream($stream, 200, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => $size,
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
