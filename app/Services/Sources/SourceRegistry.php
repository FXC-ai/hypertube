<?php

namespace App\Services\Sources;

use App\Enums\MovieSource;
use InvalidArgumentException;

final class SourceRegistry
{
    public function provider(MovieSource $source): MovieSourceProvider
    {
        return match ($source) {
            MovieSource::Wikidata, MovieSource::Whitelist => WikidataSource::factory($source),
            MovieSource::Omdb => throw new InvalidArgumentException('OMDb is a metadata provider, not a catalog source.'),
        };
    }
}
