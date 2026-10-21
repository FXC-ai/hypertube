<?php

use App\Services\Torrent\TorrentSubtitleSelector;

test('it selects every non-empty SRT and VTT file', function () {
    $files = [
        ['index' => 1, 'fileName' => 'Movie.english.srt', 'length' => 120],
        ['index' => 2, 'fileName' => 'Movie.FR.VTT', 'length' => 80],
        ['index' => 3, 'fileName' => 'notes.txt', 'length' => 50],
        ['index' => 4, 'fileName' => 'empty.srt', 'length' => 0],
    ];

    expect(app(TorrentSubtitleSelector::class)->select($files))->toBe([
        [
            'file_index' => 1,
            'file_name' => 'Movie.english.srt',
            'language' => 'eng',
            'output_file' => null,
        ],
        [
            'file_index' => 2,
            'file_name' => 'Movie.FR.VTT',
            'language' => 'und',
            'output_file' => null,
        ],
    ]);
});

test('it detects supported languages and leaves missing or ambiguous languages undetermined', function (string $fileName, string $expectedLanguage) {
    $selected = app(TorrentSubtitleSelector::class)->select([
        ['index' => 7, 'fileName' => $fileName, 'length' => 100],
    ]);

    expect($selected)->toHaveCount(1)
        ->and($selected[0]['language'])->toBe($expectedLanguage);
})->with([
    'English' => ['Movie.anglais.srt', 'eng'],
    'French' => ['Movie.francais.srt', 'fra'],
    'Italian' => ['Movie.italian.vtt', 'ita'],
    'German' => ['Movie.deutsch.srt', 'deu'],
    'missing language' => ['Movie.subtitles.srt', 'und'],
    'ambiguous language' => ['Movie.english.french.srt', 'und'],
]);
