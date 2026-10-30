<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Movie',
    type: 'object',
    description: 'Everything this server has already collected about a movie.',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'imdb_id', type: 'string', nullable: true),
        new OA\Property(property: 'imdb_rating', type: 'number', nullable: true),
        new OA\Property(property: 'production_year', type: 'integer', nullable: true),
        new OA\Property(property: 'length', type: 'integer', nullable: true, description: 'Runtime in minutes.'),
        new OA\Property(property: 'summary', type: 'string', nullable: true),
        new OA\Property(property: 'director', type: 'string', nullable: true),
        new OA\Property(property: 'producer', type: 'string', nullable: true),
        new OA\Property(property: 'writers', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'cast', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'genres', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'language', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'poster', type: 'string', format: 'uri', nullable: true),
        new OA\Property(
            property: 'subtitles',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'label', type: 'string'),
                new OA\Property(property: 'language', type: 'string', nullable: true),
                new OA\Property(property: 'url', type: 'string', format: 'uri'),
            ]),
        ),
        new OA\Property(property: 'comments_count', type: 'integer'),
        new OA\Property(property: 'source', type: 'string', nullable: true),
    ],
)]
class MovieResource extends JsonResource
{
    /**
     * Everything this server has already collected about the movie: the catalog entry
     * that brought it here plus the metadata gathered from OMDb.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $movie = $this->resource;
        $entry = $movie->sourceEntry();
        $data = $movie->movieData;

        // What OMDb collected wins over what the catalog entry carries.
        $rating = $data !== null ? $data->imdbRating : null;
        $genres = $data !== null ? $data->genres : null;

        return [
            'id' => $movie->id,
            'name' => $movie->title,
            'imdb_id' => $movie->imdb_id,
            'imdb_rating' => $rating ?? $entry?->imdb_rating,
            'production_year' => $data?->release_date?->format('Y') ?? $entry?->release_year,
            'length' => $data?->runtime,
            'summary' => $data?->plot,
            'director' => $data?->director,
            'producer' => $data?->producer,
            'writers' => $data?->writers,
            'cast' => $data?->actors,
            'genres' => $genres ?? $entry?->genreLabels(),
            'language' => $data?->language,
            'poster' => $data?->poster,
            'subtitles' => $movie->availableSubtitles(),
            'comments_count' => $this->whenCounted('comments'),
            'source' => $entry?->source->value,
        ];
    }
}
