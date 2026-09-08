<?php

namespace App\Http\Controllers;


use App\Enums\ConversionStatus;
use App\Jobs\ConvertMovie;
use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class MovieConversionController extends Controller
{
    public function store(Movie $movie): RedirectResponse
    {

        return to_route('movies.show', $movie);
    }

    public function show(Movie $movie): JsonResponse
    {
        return response()->json([
            'status' => 3,
            'playable' => True,
            'error' => null,
        ]);
    }
}
