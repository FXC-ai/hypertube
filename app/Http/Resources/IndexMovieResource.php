<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IndexMovieResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'source' => $this->source?->value,
            'imdb_id' => $this->imdb_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'selectable' => false,
            'data' => $this->getEnrichedData(),
        ];
    }

    public static function collection($resource)
    {
        return parent::collection($resource);
    }
}
