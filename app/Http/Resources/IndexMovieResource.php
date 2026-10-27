<?php

namespace App\Http\Resources;

use App\Data\MergedMovie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IndexMovieResource extends JsonResource
{
    /**
     * @var MergedMovie
     */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->movie?->id,
            'title' => $this->resource->entry->title,
            'source' => $this->resource->entry->source->value,
            'imdb_id' => $this->resource->entry->imdb_id,
            'created_at' => $this->resource->entry->created_at?->toIso8601String(),
            'watched' => $this->resource->isWatched(),
            'data' => $this->resource->data?->displayArray(),
        ];
    }
}
