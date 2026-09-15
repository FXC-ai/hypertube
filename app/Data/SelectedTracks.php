<?php

namespace App\Data;

use JsonSerializable;

final readonly class SelectedTracks implements JsonSerializable
{
    /**@paramlist<MediaStream> $subtitles */
    public function __construct(
        public MediaStream $video,
        public ?MediaStream $audio,
        public array $subtitles,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'video' => $this->video,
            'audio' => $this->audio,
            'subtitles' => $this->subtitles,
        ];
    }
}
