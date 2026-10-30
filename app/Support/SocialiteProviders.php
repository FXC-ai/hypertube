<?php

namespace App\Support;

final class SocialiteProviders
{
    /**
     * Every Omniauth strategy this application answers with, the subject asking for the
     * 42 strategy plus at least one freely chosen one. The key is the route segment,
     * the value the name shown to the user.
     *
     * @return array<string, string>
     */
    public static function labelled(): array
    {
        return [
            'github' => 'GitHub',
            'fortytwo' => '42',
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::labelled());
    }
}
