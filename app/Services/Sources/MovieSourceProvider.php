<?php

namespace App\Services\Sources;

use App\Enums\MovieSource;
use Illuminate\Http\Request;

interface MovieSourceProvider
{
    public function source(): MovieSource;

    /**
     * Query this source and return normalized items ready for the movies listing.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(Request $request, string $term, int $page, int $perPage): array;
}
