<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * The Swagger page sends what the generated spec says. A parameter whose name does not match
 * the route (swagger-php falls back to the method name, "show" or "index", when `name:` is
 * missing) is silently dropped: {movie} stayed in the URL (500), page and perPage were ignored.
 */
function generatedSpec(): array
{
    test()->artisan('l5-swagger:generate')->assertSuccessful();

    return json_decode(
        file_get_contents(config('l5-swagger.defaults.paths.docs').'/'.config('l5-swagger.documentations.default.paths.docs_json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * @return list<string> "METHOD /path" of every api route behind /api, as the spec writes them.
 */
function apiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api')
            && in_array('api', $route->gatherMiddleware(), true))
        ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' /'.$route->uri()))
        ->sort()
        ->values()
        ->all();
}

test('the spec documents every api route and nothing else', function () {
    $documented = collect(generatedSpec()['paths'])
        ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
            ->map(fn (string $method): string => strtoupper($method).' '.$path))
        ->sort()
        ->values()
        ->all();

    expect($documented)->toEqualCanonicalizing(apiRoutes());
});

test('every path parameter of the spec is named after its placeholder', function () {
    foreach (generatedSpec()['paths'] as $path => $operations) {
        preg_match_all('/\{(\w+)\}/', $path, $placeholders);

        foreach ($operations as $method => $operation) {
            $names = collect($operation['parameters'] ?? [])
                ->where('in', 'path')
                ->pluck('name')
                ->sort()
                ->values()
                ->all();

            expect($names)->toBe(collect($placeholders[1])->sort()->values()->all(), "{$method} {$path}");
        }
    }
});

test('every paginated listing documents page and perPage', function () {
    $spec = generatedSpec();

    foreach (['/api/movies', '/api/users', '/api/comments', '/api/movies/{movie}/comments'] as $path) {
        $names = collect($spec['paths'][$path]['get']['parameters'] ?? [])
            ->where('in', 'query')
            ->pluck('name')
            ->all();

        expect($names)->toBe(['page', 'perPage'], "GET {$path}");
    }
});

test('the documented token url is the only passport route', function () {
    $flow = generatedSpec()['components']['securitySchemes']['passport']['flows']['password'];

    expect($flow['tokenUrl'])->toBe('/oauth/token')
        ->and($flow['refreshUrl'])->toBe('/oauth/token');
});
