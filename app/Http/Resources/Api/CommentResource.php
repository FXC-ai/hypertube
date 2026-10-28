<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Comment',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'movie_id', type: 'integer'),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'username', type: 'string', description: 'The author of the comment.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'CommentCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Comment')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class CommentResource extends JsonResource
{
    /**
     * A comment, named by the username of whoever wrote it rather than by an id the
     * consumer would have to resolve.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;

        return [
            'id' => $comment->id,
            'movie_id' => $comment->movie_id,
            'content' => $comment->content,
            'username' => $comment->user->username,
            'created_at' => $comment->created_at?->toIso8601String(),
            'updated_at' => $comment->updated_at?->toIso8601String(),
        ];
    }
}
