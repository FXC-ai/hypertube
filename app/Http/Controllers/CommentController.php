<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Http\Requests\IndexMovieCommentRequest;
use App\Models\Comment;
use App\Models\Movie;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CommentController extends Controller
{
    public function show(Comment $comment): Response
    {
        $commentResource = new CommentResource($comment);

        return Inertia::render(
            'comments/show',
            [
                "comment" => $commentResource
            ]
        );
    }
    /**
     * Store a newly created comment in storage.
     */
    public function store(StoreCommentRequest $request, Movie $movie): RedirectResponse
    {
        $movie->comments()->create([
            'user_id' => $request->user()->id,
            'content' => $request->content,
        ]);

        return back();
    }

    /**
     * Remove the specified comment from storage.
     */
    public function destroy(Comment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return back();
    }

    /**
     * Index movie comments
     */
    public function index(Movie $movie, IndexMovieCommentRequest $request): InertiaResponse
    {
        $params = $request->validated();

        $sort = $params['sort'] ?? null;
        $dir = $params['dir'] ?? 'asc';
        $perPage = (int) ($params['perPage'] ?? 5);

        $query = $movie->comments()->with('user');

        if ($sort) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        }

        return Inertia::render(
            'movies/comments/index',
            [
                'comments' => Inertia::scroll(
                    fn () => CommentResource::collection(
                        $query->paginate($perPage)
                    ),
                ),
            ]
        );
    }
}
