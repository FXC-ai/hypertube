<?php

use Illuminate\Support\Facades\File;

/*
 * Rules of the subject that live in the repository itself rather than in a request.
 */
test('no library that streams a video from a torrent is installed', function (string $manifest) {
    $manifest = json_decode(file_get_contents(base_path($manifest)), true, flags: JSON_THROW_ON_ERROR);
    $packages = array_keys([
        ...($manifest['require'] ?? []),
        ...($manifest['require-dev'] ?? []),
        ...($manifest['dependencies'] ?? []),
        ...($manifest['devDependencies'] ?? []),
    ]);

    expect(array_filter(
        $packages,
        fn (string $package): bool => (bool) preg_match('/webtorrent|peerflix|pulsar|torrent-stream/i', $package),
    ))->toBe([]);
})->with(['composer.json', 'package.json', 'torrent-client/package.json']);

test('credentials stay out of git', function () {
    $ignored = array_map('trim', file(base_path('.gitignore')));

    expect($ignored)->toContain('.env')
        ->and(collect(file(base_path('.env.example')))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => (bool) preg_match('/^[A-Z_]*(SECRET|PASSWORD|API_KEY)=\S/', $line))
            // Placeholders only: change-me, null, your-...
            ->reject(fn (string $line): bool => (bool) preg_match('/=(change-me|null|your-[a-z-]+)$/', $line))
            ->values()
            ->all())->toBe([]);
});

test('the pages leave no debugging output in the browser console', function () {
    $files = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($file) => in_array($file->getExtension(), ['ts', 'tsx'], true))
        ->reject(fn ($file) => str_starts_with($file->getRelativePathname(), 'actions/')
            || str_starts_with($file->getRelativePathname(), 'routes/')
            || str_starts_with($file->getRelativePathname(), 'wayfinder/'))
        ->filter(fn ($file) => preg_match('/console\.(log|debug|info|warn|error|trace|group)\(/', $file->getContents()))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($files)->toBe([]);
});

test('a copy of the example environment hides error details', function () {
    // With APP_DEBUG=true any error page shows the stack trace and the SQL request.
    expect(file(base_path('.env.example'), FILE_IGNORE_NEW_LINES))->toContain('APP_DEBUG=false');
});
