<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Http\Resources\CommentResource;
use App\Models\Movie;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MovieController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(Movie $movie): InertiaResponse
    {
        // Log::channel("my_debug")->debug("MovieController : ", ["show", Auth::user()->toArray()["preferredlanguage"]]);
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
                    'preferredlanguage' => Auth::user()->preferredlanguage?->value,
                ],
                'comments' => Inertia::scroll(
                    fn () => CommentResource::collection(
                        $movie->comments()->with('user')->latest()->paginate(20)
                    ),
                ),
            ]
        );
    }
}
