<?php

namespace App\Enums;

/**
 * A criterion the movie listing can be sorted on. The request never carries a column
 * name: this enum is what stands between the query string and the ORDER BY clause.
 */
enum MovieSort: string
{
    case Popularity = 'popularity';
    case CreatedAt = 'created_at';
    case Name = 'name';
    case Year = 'year';
    case Rating = 'rating';

    /**
     * The sources table column holding this criterion.
     *
     * @return literal-string
     */
    public function column(): string
    {
        return match ($this) {
            self::Popularity => 'popularity',
            self::CreatedAt => 'created_at',
            self::Name => 'title',
            self::Year => 'release_year',
            self::Rating => 'imdb_rating',
        };
    }

    /**
     * Human readable name shown in the UI (the website is in english).
     */
    public function label(): string
    {
        return match ($this) {
            self::Popularity => 'Popularity (downloads)',
            self::CreatedAt => 'Date added',
            self::Name => 'Name',
            self::Year => 'Production year',
            self::Rating => 'IMDb rating',
        };
    }
}
