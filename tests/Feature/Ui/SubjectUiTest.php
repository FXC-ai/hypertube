<?php

use App\Support\SocialiteProviders;

/*
 * The criteria of the subject and of the evaluation sheet that live in the React
 * components rather than in a response: the corrections sheet is read on the rendered
 * pages, so these checks keep the pages honest about what they show.
 */
function pageSource(string $relativePath): string
{
    $contents = file_get_contents(resource_path('js/'.$relativePath));

    expect($contents)->not->toBeFalse();

    return $contents;
}

test('every strategy the server answers with is offered on the auth pages', function (string $file) {
    $source = pageSource('pages/auth/'.$file);

    foreach (SocialiteProviders::values() as $provider) {
        expect($source)->not->toMatch('/Sign (in|up) with (github|fortytwo)/i')
            ->and($source)->toContain('Object.entries(providers)')
            ->and($source)->toContain('redirect.url(provider)');
    }
})->with(['login.tsx', 'register.tsx']);

test('logging out is one click away on every page an authenticated user sees', function (string $file) {
    expect(pageSource($file))->toContain('logout()');
})->with([
    'the header of the public pages' => 'components/site-header.tsx',
    'the sidebar of the app pages' => 'components/app-sidebar.tsx',
    'the header of the app pages on a phone' => 'components/app-sidebar-header.tsx',
]);

test('the library loads the next page while scrolling and never offers a link for it', function () {
    $source = pageSource('pages/movies/index.tsx');

    expect($source)->toContain('<InfiniteScroll')
        ->and($source)->not->toMatch('/load more|show more|next page|pagination/i');
});

test('a thumbnail carries the name, the year, the grade and the cover', function () {
    $source = pageSource('pages/movies/index.tsx');

    expect($source)->toContain('movie.title')
        ->and($source)->toContain('productionYear(movie)')
        ->and($source)->toContain('movie.data?.imdbRating')
        ->and($source)->toContain('movie.data?.poster')
        ->and($source)->toContain('default-poster.png');
});

test('a watched movie is told apart from an unwatched one', function () {
    expect(pageSource('pages/movies/index.tsx'))->toContain('movie.watched')
        ->and(pageSource('pages/movies/index.tsx'))->toContain('Already watched');
});

test('the listing is sortable and filterable on the criteria the subject lists', function (string $criterion) {
    expect(pageSource('pages/movies/index.tsx'))->toContain($criterion);
})->with([
    'name' => 'sort',
    'genre' => 'genre',
    'imdb grade' => 'ratingFrom',
    'production year' => 'yearFrom',
    'watched' => 'watched',
]);

test('the video page shows the casting, the year, the length, the grade and the cover', function (string $needle) {
    expect(pageSource('pages/movies/show.tsx'))->toContain($needle);
})->with([
    'summary' => 'Plot:',
    'producer' => 'Producer:',
    'director' => 'Director:',
    'main cast' => 'Actors:',
    'production year' => 'Year:',
    'length' => 'Runtime:',
    'imdb grade' => 'IMDB Rating',
    'cover' => 'moviePageData.movieData.poster',
    'player' => 'HlsPlayer',
]);

test('subtitles are handed to the player where they can be selected', function () {
    expect(pageSource('pages/movies/show.tsx'))->toContain('kind="subtitles"')
        ->and(pageSource('pages/movies/show.tsx'))->toContain('subtitlePreference');
});

test('the pages keep the header, the main section and the footer the subject asks for', function () {
    $welcome = pageSource('pages/welcome.tsx');
    $appLayout = pageSource('layouts/app/app-sidebar-layout.tsx');
    $authLayout = pageSource('layouts/auth/auth-simple-layout.tsx');

    expect($welcome)->toContain('<SiteHeader />')
        ->and($welcome)->toContain('<main')
        ->and($welcome)->toContain('<SiteFooter />')
        ->and($appLayout)->toContain('<AppSidebarHeader')
        ->and($appLayout)->toContain('<SiteFooter')
        ->and($authLayout)->toContain('<main');
});
