<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Http\Requests\IndexMovieRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\IndexMovieResource;
use App\Models\Movie;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Illuminate\Support\Facades\Log;
use App\Enums\DownloadStatus;
use Illuminate\Support\Facades\Storage;

class MovieController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(Movie $movie): InertiaResponse
    {
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
                    'movieData' => $movie->getEnrichedData(),
                    'download_status' => $movie->download_status->value,
                    'download_error' => $movie->download_status === DownloadStatus::Unsupported
                        ? $movie->download_error
                        : null,
                    'can_prepare' => $movie->download_status !== DownloadStatus::Unsupported && in_array($movie->conversion_status, [ConversionStatus::Pending, ConversionStatus::Failed], true),
                    'external_subtitles_status' => $movie->external_subtitles_status->value,
                    'external_subtitles' => collect($movie->external_subtitles ?? [])
                        ->filter(fn(array $subtitle): bool => is_string($subtitle['output_file'] ?? null))
                        ->map(fn(array $subtitle): array => [
                            'id' => $subtitle['file_index'],
                            'label' => $subtitle['file_name'] . ' — External',
                            'language' => $subtitle['language'],
                            'url' => Storage::disk('public')->url(
                                "movies/{$movie->id}/{$subtitle['output_file']}"
                            ),
                        ])
                        ->values()
                        ->all(),
                ],
                'comments' => Inertia::scroll(
                    fn() => CommentResource::collection(
                        $movie->comments()->with('user')->latest()->paginate(20)
                    ),
                ),
            ]
        );
    }

    public function index(IndexMovieRequest $request): InertiaResponse
    {
        $params = $request->validated();

        $search = $params['search'] ?? null;
        $sort = $params['sort'] ?? null;
        $dir = $params['dir'] ?? 'asc';
        $perPage = (int) ($params['perPage'] ?? 5);

        Log::channel('my_debug')->debug('search = ', [$search]);

        $query = Movie::query();

        if ($search) {
            $query->where('title', 'like', '%' . $search . '%');
        }

        if ($sort) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        }

        return Inertia::render(
            'movies/index',
            [
                'movies' => Inertia::scroll(
                    fn() => IndexMovieResource::collection($query->paginate($perPage))
                ),
                'filters' => [
                    'search' => $search,
                ],
            ]
        );
    }
}
