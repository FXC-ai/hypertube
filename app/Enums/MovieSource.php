<?php

namespace App\Enums;

enum MovieSource: string
{
    case Wikidata = 'wikidata';
    case Whitelist = 'whitelist';
    case Omdb = 'omdb';
    // A torrent or magnet link an admin imported: not a catalog, never listed in the search.
    case Import = 'import';

    /**
     * Human readable name shown in the UI (the website is in english).
     */
    public function label(): string
    {
        return match ($this) {
            self::Wikidata => 'Wikidata (public domain)',
            self::Whitelist => 'Whitelist',
            self::Omdb => 'OMDb',
            self::Import => 'Imported',
        };
    }

    /**
     * Whether the catalog only offers content that is legally distributable.
     */
    public function isLegal(): bool
    {
        return true;
    }

    /**
     * Sources enabled for a user that has never changed its preferences.
     *
     * @return array<string, bool>
     */
    public static function defaultPreferences(): array
    {
        return [
            self::Wikidata->value => true,
            self::Whitelist->value => true,
        ];
    }

    /**
     * Catalog sources feed the sources table, OMDb only provides metadata.
     *
     * @return list<self>
     */
    public static function catalogSources(): array
    {
        return [self::Wikidata, self::Whitelist];
    }

    /**
     * Whether this source can be queried to find movies to watch.
     */
    public function isCatalog(): bool
    {
        return in_array($this, self::catalogSources(), true);
    }
}
