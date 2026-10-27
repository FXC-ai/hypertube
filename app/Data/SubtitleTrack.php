<?php

namespace App\Data;

final readonly class SubtitleTrack
{
    public function __construct(
        public int $inputIndex,
        public int $streamIndex,
        public string $language,
        public string $title,
        public string $origin,
    ) {}

    public function sourceMap(): string
    {
        return "{$this->inputIndex}:{$this->streamIndex}";
    }

    public function hlsLanguage(): string
    {
        return match ($this->language) {
            'fra' => 'fr',
            'eng' => 'en',
            'deu' => 'de',
            'ita' => 'it',
            default => 'und',
        };
    }
}
