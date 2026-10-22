<?php

use App\Services\Sources\YtsSource;
use Illuminate\Support\Facades\Http;

test('yts source search returns normalized items', function () {
    Http::fake([
        'api.yts.gg/api/v1/movies*' => Http::response([
            'data' => [
                'movies' => [
                    [
                        'id' => 79216,
                        'imdb_code' => 'tt0087643',
                        'title' => 'Love Scenes',
                        'year' => 1984,
                        'rating' => 4.6,
                        'runtime' => 90,
                        'genres' => ['Drama'],
                        'summary' => 'Plot',
                        'language' => 'en',
                        'mpa_rating' => 'R',
                        'large_cover_image' => 'https://yts.gg/cover.jpg',
                        'state' => 'ok',
                        'torrents' => [
                            ['url' => 'https://yts.gg/torrent/download/A', 'hash' => 'A', 'quality' => '720p', 'seeds' => 3, 'peers' => 1, 'size_bytes' => 868420157],
                        ],
                    ]
                ],
                'info' => ['page' => 1, 'pages' => 1, 'limit' => 5],
            ],
        ]),
    ]);

    $result = YtsSource::factory()->search(request(), 'love', 1, 5);

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['title'])->toBe('Love Scenes');
    expect($result['items'][0]['source'])->toBe('yts');
    expect($result['total'])->toBe(5);
});
