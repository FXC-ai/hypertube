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
        return [
            'id' => $this->id,
            'movie_id' => $this->movie_id,
            'user_id' => $this->user_id,
            'content' => $this->content,
            'created_at' => $this->created_at,
            'user' => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'profilepicture' => $this->user->profilepicture,
            ],
            'can_delete' => auth()->check() && auth()->id() === $this->user_id,
        ];
    }
}
