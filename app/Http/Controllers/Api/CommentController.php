<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaginationRequest;
use App\Http\Requests\Api\StoreCommentRequest;
use App\Http\Requests\Api\StoreMovieCommentRequest;
use App\Http\Requests\Api\UpdateCommentRequest;
use App\Http\Resources\Api\CommentResource;
use App\Models\Comment;
use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class CommentController extends Controller
{
    /**
     * The most recent comments of this instance.
     */
    #[OA\Get(
        path: '/api/comments',
        summary: 'List the latest comments',
        operationId: 'listComments',
        tags: ['Comments'],
        security: [['passport' => []]],
        parameters: [
            new OA\QueryParameter(parameter: 'page', description: 'The page to read.', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\QueryParameter(parameter: 'perPage', description: 'Rows per page, 20 by default.', schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The newest comments, most recent first.', ref: '#/components/schemas/CommentCollection'),
            new OA\Response(response: 401, description: 'No valid access token.'),
        ],
    )]
    public function index(PaginationRequest $request): ResourceCollection
    {
        return CommentResource::collection(
            Comment::query()->with('user')->latest('created_at')->orderByDesc('id')->paginate($request->perPage())
        );
    }

    /**
     * One comment.
     */
    #[OA\Get(
        path: '/api/comments/{comment}',
        summary: 'Read a comment',
        operationId: 'showComment',
        tags: ['Comments'],
        security: [['passport' => []]],
        parameters: [
            new OA\PathParameter(parameter: 'comment', description: 'The id of the comment.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The comment.', ref: '#/components/schemas/Comment'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 404, description: 'Unknown comment.'),
        ],
    )]
    public function show(Comment $comment): CommentResource
    {
        $comment->load('user');

        return new CommentResource($comment);
    }

    /**
     * The comments of one movie.
     */
    #[OA\Get(
        path: '/api/movies/{movie}/comments',
        summary: 'List the comments of a movie',
        operationId: 'listMovieComments',
        tags: ['Comments'],
        security: [['passport' => []]],
        parameters: [
            new OA\PathParameter(parameter: 'movie', description: 'The id of the movie.', schema: new OA\Schema(type: 'integer')),
            new OA\QueryParameter(parameter: 'page', description: 'The page to read.', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\QueryParameter(parameter: 'perPage', description: 'Rows per page, 20 by default.', schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The comments of that movie.', ref: '#/components/schemas/CommentCollection'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 404, description: 'Unknown movie.'),
        ],
    )]
    public function indexForMovie(Movie $movie, PaginationRequest $request): ResourceCollection
    {
        return CommentResource::collection(
            $movie->comments()->with('user')->paginate($request->perPage())
        );
    }

    /**
     * Post a comment on a movie named by the payload.
     */
    #[OA\Post(
        path: '/api/comments',
        summary: 'Comment a movie',
        operationId: 'storeComment',
        tags: ['Comments'],
        security: [['passport' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'The author and the date are filled by the server.',
            content: new OA\JsonContent(
                required: ['comment', 'movie_id'],
                properties: [
                    new OA\Property(property: 'comment', type: 'string', maxLength: 5000),
                    new OA\Property(property: 'movie_id', type: 'integer'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The comment as it was stored.', ref: '#/components/schemas/Comment'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 422, description: 'The payload is invalid.'),
        ],
    )]
    public function store(StoreCommentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $comment = Movie::query()
            ->whereKey($validated['movie_id'])
            ->firstOrFail()
            ->comments()
            ->create([
                'user_id' => $request->user()->id,
                'content' => $validated['comment'],
            ]);

        return (new CommentResource($comment->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Post a comment on the movie the route names.
     */
    #[OA\Post(
        path: '/api/movies/{movie}/comments',
        summary: 'Comment a movie named in the path',
        operationId: 'storeMovieComment',
        tags: ['Comments'],
        security: [['passport' => []]],
        parameters: [
            new OA\PathParameter(parameter: 'movie', description: 'The id of the movie.', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'The author and the date are filled by the server.',
            content: new OA\JsonContent(
                required: ['comment'],
                properties: [
                    new OA\Property(property: 'comment', type: 'string', maxLength: 5000),
                    new OA\Property(property: 'movie_id', type: 'integer', description: 'Accepted only when it names the movie of the path.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The comment as it was stored.', ref: '#/components/schemas/Comment'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 404, description: 'Unknown movie.'),
            new OA\Response(response: 422, description: 'The payload is invalid.'),
        ],
    )]
    public function storeForMovie(StoreMovieCommentRequest $request, Movie $movie): JsonResponse
    {
        $comment = $movie->comments()->create([
            'user_id' => $request->user()->id,
            'content' => $request->validated()['comment'],
        ]);

        return (new CommentResource($comment->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rewrite a comment, which only its author may do.
     */
    #[OA\Patch(
        path: '/api/comments/{comment}',
        summary: 'Rewrite one of your own comments',
        operationId: 'updateComment',
        tags: ['Comments'],
        security: [['passport' => []]],
        parameters: [
            new OA\PathParameter(parameter: 'comment', description: 'The id of the comment, which must be yours.', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'The username is accepted because the subject documents it, and is never written.',
            content: new OA\JsonContent(
                required: ['comment'],
                properties: [
                    new OA\Property(property: 'comment', type: 'string', maxLength: 5000),
                    new OA\Property(property: 'username', type: 'string', maxLength: 255),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The comment as it now reads.', ref: '#/components/schemas/Comment'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 403, description: 'A comment may only be rewritten by its author.'),
            new OA\Response(response: 404, description: 'Unknown comment.'),
            new OA\Response(response: 422, description: 'The payload is invalid.'),
        ],
    )]
    public function update(UpdateCommentRequest $request, Comment $comment): CommentResource
    {
        $this->authorize('update', $comment);

        $comment->update(['content' => $request->validated()['comment']]);

        return new CommentResource($comment->load('user'));
    }

    /**
     * Drop a comment, which only its author may do.
     */
    #[OA\Delete(
        path: '/api/comments/{comment}',
        summary: 'Delete one of your own comments',
        operationId: 'destroyComment',
        tags: ['Comments'],
        security: [['passport' => []]],
        parameters: [
            new OA\PathParameter(parameter: 'comment', description: 'The id of the comment, which must be yours.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'The comment is gone.'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 403, description: 'A comment may only be deleted by its author.'),
            new OA\Response(response: 404, description: 'Unknown comment.'),
        ],
    )]
    public function destroy(Comment $comment): Response
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->noContent();
    }
}
