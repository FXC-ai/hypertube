<?php

use App\Services\Torrent\TorrentVideoSelector;

/**
 * @return array{index: int, path: string, fileName: string, length: int, kind: string, suggested: bool}
 */
function torrentSelectorFile(int $index, string $path, int $length): array
{
    return [
        'index' => $index,
        'path' => $path,
        'fileName' => basename($path),
        'length' => $length,
        'kind' => 'untrusted',
        'suggested' => false,
    ];
}

test('it prioritizes MP4 files over larger MKV files', function () {
    $selected = (new TorrentVideoSelector)->select([
        torrentSelectorFile(0, 'Movie/movie.mkv', 2000),
        torrentSelectorFile(1, 'Movie/movie.MP4', 1000),
    ]);

    expect($selected)
        ->not->toBeNull()
        ->and($selected['index'])->toBe(1);
});

test('it selects the largest file among candidates with the same extension', function () {
    $selected = (new TorrentVideoSelector)->select([
        torrentSelectorFile(0, 'Movie/small.mp4', 1000),
        torrentSelectorFile(1, 'Movie/large.mp4', 2000),
        torrentSelectorFile(2, 'Movie/larger.mkv', 3000),
    ]);

    expect($selected)
        ->not->toBeNull()
        ->and($selected['index'])->toBe(1);
});

test('it selects the lowest index when candidates have the same format and size', function () {
    $selected = (new TorrentVideoSelector)->select([
        torrentSelectorFile(8, 'Movie/second.mkv', 1000),
        torrentSelectorFile(3, 'Movie/first.mkv', 1000),
    ]);

    expect($selected)
        ->not->toBeNull()
        ->and($selected['index'])->toBe(3);
});

test('it rejects empty files and unsupported extensions', function () {
    $selected = (new TorrentVideoSelector)->select([
        torrentSelectorFile(0, 'Movie/empty.mp4', 0),
        torrentSelectorFile(1, 'Movie/movie.webm', 2000),
        torrentSelectorFile(2, 'Movie/movie.srt', 5),
    ]);

    expect($selected)->toBeNull();
});
