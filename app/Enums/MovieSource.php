<?php

namespace App\Enums;

enum MovieSource: string
{
    case Wikidata = 'wikidata';
    case Yts = 'yts';
    case Omdb = 'omdb';

    /**
     * Human readable name shown in the UI (the website is in english).
     */
    public function label(): string
    {
        return match ($this) {
            self::Wikidata => 'Wikidata (public domain)',
            self::Yts => 'YTS',
            self::Omdb => 'OMDb',
        };
    }

    /**
     * Whether the catalog only offers content that is legally distributable.
     */
    public function isLegal(): bool
    {
        return $this !== self::Yts;
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
            self::Yts->value => false,
        ];
    }

    /**
     * Catalog sources feed the movies table, OMDb only provides metadata.
     *
     * @return list<self>
     */
    public static function catalogSources(): array
    {
        return [self::Wikidata, self::Yts];
    }
}
