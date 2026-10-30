<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;

        return [
            'id' => $comment->id,
            'movie_id' => $comment->movie_id,
            'user_id' => $comment->user_id,
            'content' => $comment->content,
            'created_at' => $comment->created_at,
            'user' => [
                'id' => $comment->user->id,
                'username' => $comment->user->username,
                'profilepicture' => $comment->user->profilepicture,
            ],
            'can_delete' => auth()->check() && auth()->id() === $comment->user_id,
        ];
    }
}
