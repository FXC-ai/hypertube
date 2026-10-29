<?php

namespace App\Enums;

enum Languages: string
{
    case English = 'english';
    case French = 'french';
    case German = 'german';
    case Italian = 'italian';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'French',
            self::German => 'German',
            self::Italian => 'Italian',
        };
    }

    public static function fromMediaCode(?string $mediaCode): ?self
    {
        return match ($mediaCode) {
            'eng' => self::English,
            'fra' => self::French,
            'deu' => self::German,
            'ita' => self::Italian,
            default => null,
        };
    }
}
