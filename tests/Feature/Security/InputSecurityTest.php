<?php

use App\Models\Comment;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

/*
 * The subject's eliminatory rules: no injection of HTML or JavaScript, no alteration of an SQL
 * request, no upload of unwanted content.
 */
const XSS_PAYLOAD = '<script>alert(1)</script><img src=x onerror=alert(2)>';

test('a comment carrying html is stored and served as plain text on the web', function () {
    $user = User::factory()->create();
    $movie = Movie::factory()->create();

    $this->actingAs($user)
        ->post(route('movies.comments.store', $movie), ['content' => XSS_PAYLOAD])
        ->assertSessionHasNoErrors();

    expect(Comment::query()->sole()->content)->toBe(XSS_PAYLOAD);

    // React prints props as text: the page gets the string itself, never markup built from it.
    $this->actingAs($user)
        ->get(route('movies.show', $movie))
        ->assertInertia(fn ($page) => $page->where('comments.data.0.content', XSS_PAYLOAD));
});

test('a comment carrying html is served as an escaped json string by the api', function () {
    Passport::actingAs(User::factory()->create());
    $movie = Movie::factory()->create();

    $id = $this->postJson("/api/movies/{$movie->id}/comments", ['comment' => XSS_PAYLOAD])
        ->assertCreated()
        ->json('data.id');

    $response = $this->getJson("/api/comments/{$id}")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->json('data.content'))->toBe(XSS_PAYLOAD);
});

test('names carrying html are kept as text on the public profile', function () {
    $viewer = User::factory()->create();
    $profile = User::factory()->create(['firstname' => XSS_PAYLOAD]);

    $this->actingAs($viewer)
        ->get(route('users.show', $profile))
        ->assertInertia(fn ($page) => $page->where('user.data.firstname', XSS_PAYLOAD));
});

test('the frontend never injects raw html', function () {
    $files = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($file) => in_array($file->getExtension(), ['ts', 'tsx'], true))
        ->filter(fn ($file) => str_contains($file->getContents(), 'dangerouslySetInnerHTML')
            || str_contains($file->getContents(), '.innerHTML'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($files)->toBe([]);
});

test('an sql injection in the title search is only text to search for', function (string $term) {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('movies.index', ['search' => $term]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('movies.data', []));

    expect(User::query()->count())->toBe(1);
})->with([
    "' OR '1'='1",
    "'; DROP TABLE users; --",
    '%',
    '_',
    '\\',
]);

test('a profile picture that is not a real image is refused', function (UploadedFile $file) {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('update.avatar'), ['profilepicture' => $file])
        ->assertSessionHasErrors('profilepicture');

    expect($user->refresh()->profilepicture)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'svg, which can carry scripts' => fn () => UploadedFile::fake()->createWithContent(
        'avatar.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    ),
    'php renamed to jpg' => fn () => UploadedFile::fake()->createWithContent('avatar.jpg', '<?php phpinfo();'),
    'html' => fn () => UploadedFile::fake()->createWithContent('avatar.html', XSS_PAYLOAD),
    'pdf' => fn () => UploadedFile::fake()->create('avatar.pdf', 10, 'application/pdf'),
]);

test('the api only takes a public web link to a real picture', function (string $url) {
    Storage::fake('public');
    Http::fake(['*' => Http::response('<?php phpinfo();', 200)]);
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson("/api/users/{$user->id}", ['profilepicture' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('profilepicture');

    expect($user->refresh()->profilepicture)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'javascript' => 'javascript:alert(1)',
    'data url' => 'data:text/html,<script>alert(1)</script>',
    'ftp' => 'ftp://example.com/avatar.png',
    'not a url' => 'not a url',
    // The server fetches the link: an internal address would let a user probe the network.
    'loopback' => 'http://127.0.0.1/avatar.png',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'private network' => 'http://192.168.1.1/avatar.png',
    'not an image' => 'https://93.184.215.14/avatar.png',
]);

test('the api stores its own copy of a picture given by link', function () {
    Storage::fake('public');
    Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/avatar.jpeg')), 200)]);
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson("/api/users/{$user->id}", ['profilepicture' => 'https://93.184.215.14/avatar.png'])
        ->assertOk();

    $path = $user->refresh()->profilepicture;

    expect($path)->toStartWith('avatars/')->toEndWith('.jpg');
    Storage::disk('public')->assertExists($path);
});
