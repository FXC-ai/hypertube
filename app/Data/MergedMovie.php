<?php

namespace App\Data;

use App\Models\Movie;
use App\Models\MovieData;
use App\Models\Source;

/**
 * One merged search result: what the catalog offers, what this server already has,
 * and the canonical metadata of the movie.
 */
final readonly class MergedMovie
{
    public function __construct(
        public Source $entry,
        public ?Movie $movie,
        public ?MovieData $data,
    ) {}

    /**
     * The movie was already loaded on this server.
     */
    public function isLocal(): bool
    {
        return $this->movie !== null;
    }
}
