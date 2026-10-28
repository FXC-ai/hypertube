<?php

namespace App\Data;

use App\Enums\WatchedFilter;

/**
 * What a catalog source has to answer: the term, the page of the merged listing,
 * and the watched restriction of the user asking for it.
 */
final readonly class MovieSearch
{
    public function __construct(
        public string $term,
        public int $page,
        public int $perPage,
        public string $sort,
        public string $direction,
        public WatchedFilter $watched,
        public ?int $userId,
    ) {}

    /**
     * Every source has to hand over enough rows to cover the requested page, since
     * the merged listing slices the window built from all of them.
     */
    public function windowSize(): int
    {
        return $this->page * $this->perPage;
    }
}
