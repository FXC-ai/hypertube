<?php

use App\Enums\DownloadStatus;
use App\Enums\MovieSource;
use App\Jobs\DownloadMovie;
use App\Models\Movie;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

const MAGNET = 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=Test.Video&tr=udp%3A%2F%2Ftracker.example.test%3A6969';

function admin(): User
{
    return User::factory()->admin()->create();
}

test('a guest cannot import a torrent link', function () {
    $this->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertRedirect(route('login'));
});

/*
 * Importing a link makes the server fetch it: only an admin, created with
 * `php artisan user:admin`, may import.
 */
test('a user who is not an admin cannot import a link', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertForbidden();

    expect(Movie::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the import form is only shown to an admin', function () {
    $this->actingAs(User::factory()->create())->get(route('movies.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', false));

    $this->actingAs(admin())->get(route('movies.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', true));
});

test('an admin can add a video from a magnet link', function () {
    Queue::fake();

    $response = $this->actingAs(admin())
        ->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertRedirect();

    $movie = Movie::query()->firstOrFail();

    $response->assertRedirect(route('movies.show', $movie));

    expect($movie->torrent_url)->toBe(MAGNET)
        ->and($movie->download_status)->toBe(DownloadStatus::Pending)
        ->and(Source::query()->where('imdb_id', $movie->imdb_id)->firstOrFail()->title)
        ->toBe('Test.Video');

    Queue::assertPushed(DownloadMovie::class, fn (DownloadMovie $job): bool => $job->movieId === $movie->id);
});

test('an imported video is its own source, never listed in the search', function () {
    Queue::fake();

    $this->actingAs(admin())->post(route('movies.import'), ['torrent_url' => MAGNET]);

    $movie = Movie::query()->sole();

    expect($movie->source)->toBe(MovieSource::Import)
        ->and(MovieSource::Import->isCatalog())->toBeFalse()
        ->and(Source::where('source', MovieSource::Whitelist->value)->count())->toBe(0);
});

test('importing the same magnet link again reuses the same movie', function () {
    Queue::fake();
    $admin = admin();

    $this->actingAs($admin)
        ->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertRedirect();

    $movie = Movie::query()->firstOrFail();

    $this->actingAs($admin)
        ->post(route('movies.import'), ['torrent_url' => MAGNET])
        ->assertRedirect(route('movies.show', $movie));

    expect(Movie::count())->toBe(1)
        ->and(Source::where('source', MovieSource::Import->value)->count())->toBe(1);
});

test('a non torrent URL is refused with validation', function () {
    $this->actingAs(admin())
        ->post(route('movies.import'), ['torrent_url' => 'https://example.test/not-a-torrent'])
        ->assertSessionHasErrors('torrent_url');

    expect(Movie::count())->toBe(0);
});

/*
 * The Client Torrent fetches the link from inside the Docker network: even an admin
 * cannot point it at the app, the database or the cloud metadata service.
 */
test('a torrent file on an internal host is refused', function (string $url) {
    Queue::fake();

    $this->actingAs(admin())
        ->post(route('movies.import'), ['torrent_url' => $url])
        ->assertSessionHasErrors(['torrent_url' => 'The torrent URL may only point to a public host.']);

    expect(Movie::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'loopback' => 'http://127.0.0.1/movie.torrent',
    'localhost' => 'http://localhost:8000/movie.torrent',
    'cloud metadata' => 'http://169.254.169.254/latest/movie.torrent',
    'private network' => 'https://10.0.0.5/movie.torrent',
    'ipv6 loopback' => 'http://[::1]/movie.torrent',
]);

test('a torrent file on any public host is accepted', function (string $url) {
    Queue::fake();

    $this->actingAs(admin())
        ->post(route('movies.import'), ['torrent_url' => $url])
        ->assertSessionHasNoErrors();

    expect(Movie::query()->sole()->torrent_url)->toBe($url);
})->with([
    'archive.org' => 'https://archive.org/download/his_girl_friday/his_girl_friday_archive.torrent',
    'webtorrent.io' => 'https://webtorrent.io/torrents/sintel.torrent',
    'another public site' => 'https://93.184.215.14/movies/sintel.torrent',
]);

test('a magnet loses the links to internal hosts and keeps the public ones, whatever the site', function () {
    Queue::fake();
    $hash = 'xt=urn:btih:0123456789abcdef0123456789abcdef01234567';
    $public = [
        'dn=Sintel',
        'tr=udp%3A%2F%2F93.184.215.14%3A6969',
        'tr=https%3A%2F%2F93.184.215.14%2Fannounce',
        'tr=wss%3A%2F%2F93.184.215.14',
        'ws=https%3A%2F%2F93.184.215.14%2Ftorrents%2F',
        'xs=https%3A%2F%2F93.184.215.14%2Fsintel.torrent',
    ];
    $internal = [
        'xs=http%3A%2F%2F127.0.0.1%3A8000%2Fmovie.torrent',
        'xs.1=http%3A%2F%2F192.168.1.1%2Fmovie.torrent',
        'as=http%3A%2F%2F169.254.169.254%2Flatest',
        'ws=http%3A%2F%2F172.16.0.2%2F',
        'tr=udp%3A%2F%2F127.0.0.1%3A6969',
        'tr=wss%3A%2F%2Flocalhost%3A8000',
        'xs=file%3A%2F%2F%2Fetc%2Fpasswd',
        'xs=wss%3A%2F%2F93.184.215.14%2Fmovie.torrent',
    ];

    $this->actingAs(admin())
        ->post(route('movies.import'), ['torrent_url' => 'magnet:?'.implode('&', [$hash, ...$internal, ...$public])])
        ->assertSessionHasNoErrors();

    expect(Movie::query()->sole()->torrent_url)->toBe('magnet:?'.implode('&', [$hash, ...$public]));
});

test('the magnets of webtorrent.io and of the evaluation sheet are accepted', function (string $magnet, string $kept) {
    Queue::fake();

    $this->actingAs(admin())
        ->post(route('movies.import'), ['torrent_url' => $magnet])
        ->assertSessionHasNoErrors();

    // Trackers come and go (tracker.btorrent.xyz now resolves to 127.0.0.1 and is dropped):
    // only the links that matter for each magnet are checked.
    expect(Movie::query()->sole()->torrent_url)
        ->toStartWith(explode('&', $magnet)[0])
        ->toContain($kept);
})->with([
    // udp and WebSocket trackers, a web seed and the exact source of the .torrent.
    'webtorrent.io sintel' => [
        'magnet:?xt=urn:btih:08ada5a7a6183aae1e09d831df6748d566095a10&dn=Sintel&tr=udp%3A%2F%2Fexplodie.org%3A6969&tr=udp%3A%2F%2Ftracker.coppersurfer.tk%3A6969&tr=udp%3A%2F%2Ftracker.empire-js.us%3A1337&tr=udp%3A%2F%2Ftracker.leechers-paradise.org%3A6969&tr=udp%3A%2F%2Ftracker.opentrackr.org%3A1337&tr=wss%3A%2F%2Ftracker.btorrent.xyz&tr=wss%3A%2F%2Ftracker.fastcast.nz&tr=wss%3A%2F%2Ftracker.openwebtorrent.com&ws=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2F&xs=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2Fsintel.torrent',
        'xs=https%3A%2F%2Fwebtorrent.io%2Ftorrents%2Fsintel.torrent',
    ],
    'evaluation sheet' => [
        'magnet:?xt=urn:btih:79816060ea56d56f2a2148cd45705511079f9bca&dn=TPB.AFK.2013.720p.h264-SimonKlose&tr=udp%3A%2F%2Ftracker.openbittorrent.com%3A80&tr=udp%3A%2F%2Fopen.demonii.com%3A1337&tr=udp%3A%2F%2Ftracker.coppersurfer.tk%3A6969&tr=udp%3A%2F%2Fexodus.desync.com%3A6969',
        'dn=TPB.AFK.2013.720p.h264-SimonKlose',
    ],
]);
