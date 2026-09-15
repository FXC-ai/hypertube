<?php

namespace App\Data;

use JsonSerializable;
use Override;

final readonly class MediaStream implements JsonSerializable
{
    /**
     *@paramarray<string, mixed> $disposition
     */
    public function __construct(
        public int $index,
        public string $type,
        public string $codec,
        public string $language,
        public string $title,
        public array $disposition,
    ) {}

    public function isAttachedPicture(): bool
    {
        return (bool) ($this->disposition['attached_pic'] ?? false);
    }

    public function isTextSubtitle(): bool
    {
        return in_array($this->codec, [
            'subrip',
            'srt',
            'ass',
            'ssa',
            'webvtt',
            'mov_text',
            'text',
        ], true);
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

    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'index' => $this->index,
            'type' => $this->type,
            'codec' => $this->codec,
            'language' => $this->language,
            'title' => $this->title,
            'disposition' => $this->disposition,
        ];
    }
}
