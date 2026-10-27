<?php

namespace App\Services\Sources;

use App\Enums\MovieSource;
use App\Models\Source;
use Illuminate\Http\Request;

interface MovieSourceProvider
{
    public function source(): MovieSource;

    /**
     * Query this source and return the catalog entries matching the search term.
     *
     * The returned window holds enough rows to cover the requested page, so the
     * merged listing can slice it after every source has been queried.
     *
     * @return array{items: list<Source>, total: int}
     */
    public function search(Request $request, string $term, int $page, int $perPage): array;

    /**
     * The entry of a single movie in this source, used to materialize it locally.
     */
    public function entry(string $imdbId): ?Source;
}
