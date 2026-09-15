<?php

namespace App\Data;

final readonly class MediaInfo
{
    /**@paramlist<MediaStream> $streams */
    public function __construct(public array $streams) {}

    /**@return list<MediaStream> */
    public function videos(): array
    {
        return array_values(array_filter(
            $this->streams,
            fn(MediaStream $stream): bool => $stream->type === 'video' && ! $stream->isAttachedPicture(),
        ));
    }

    /**@return list<MediaStream> */
    public function audios(): array
    {
        return array_values(array_filter(
            $this->streams,
            fn(MediaStream $stream): bool => $stream->type === 'audio',
        ));
    }

    /**@return list<MediaStream> */
    public function subtitles(): array
    {
        return array_values(array_filter(
            $this->streams,
            fn(MediaStream $stream): bool => $stream->type === 'subtitle'
                && $stream->isTextSubtitle(),
        ));
    }
}
