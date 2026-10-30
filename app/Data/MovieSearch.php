<?php

namespace App\Data;

use App\Enums\MovieSort;
use App\Enums\WatchedFilter;

/**
 * What a catalog source has to answer: the term, the page of the merged listing, the
 * watched restriction and the criteria the listing is filtered and sorted on.
 */
final readonly class MovieSearch
{
    /**
     * @param  'asc'|'desc'  $direction
     * @param  string|null  $genre  a genre label exactly as the catalog stores it
     * @param  int|null  $yearFrom  the oldest production year accepted
     * @param  int|null  $yearTo  the most recent production year accepted
     * @param  float|null  $ratingFrom  the lowest IMDb grade accepted
     * @param  float|null  $ratingTo  the highest IMDb grade accepted
     */
    public function __construct(
        public string $term,
        public int $page,
        public int $perPage,
        public MovieSort $sort,
        public string $direction,
        public WatchedFilter $watched,
        public ?int $userId,
        public ?string $genre = null,
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public ?float $ratingFrom = null,
        public ?float $ratingTo = null,
    ) {}

    /**
     * Every source has to hand over enough rows to cover the requested page, since
     * the merged listing slices the window built from all of them.
     */
    public function windowSize(): int
    {
        return $this->page * $this->perPage;
    }

    /**
     * The genre as the catalog stores it: a lowercase label wrapped in pipes, matched
     * whole so "film" never matches "drama film".
     */
    public function genrePattern(): string
    {
        $genre = preg_replace('/[%_]/', '', mb_strtolower(trim((string) $this->genre)));

        return '%|'.$genre.'|%';
    }
}
