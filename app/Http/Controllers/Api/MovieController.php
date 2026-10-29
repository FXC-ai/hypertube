<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaginationRequest;
use App\Http\Resources\Api\MovieResource;
use App\Http\Resources\Api\MovieSummaryResource;
use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Hypertube API',
    version: '1.0.0',
    description: <<<'TEXT'
    The RESTful API of this Hypertube instance, secured by OAuth2 (Laravel Passport).

    **Getting a token** - `POST /oauth/token` with the confidential client created by
    `php artisan api:client` (its id and secret live in `.env` as `PASSPORT_API_CLIENT_ID`
    and `PASSPORT_API_CLIENT_SECRET`):

    ```
    grant_type=password&username=<email or username>&password=<password>
    &client_id=<client id>&client_secret=<client secret>
    ```

    The returned `access_token` is then sent as `Authorization: Bearer <token>`.

    **RESTfulness** - collections are plural nouns, verbs are HTTP methods, and the
    codes mean what they say: 200 read, 201 created, 204 deleted, 401 missing token,
    403 forbidden, 404 unknown resource, 405 unsupported method, 422 invalid payload.
    No other endpoint than the ones documented here is exposed.
    TEXT
)]
#[OA\SecurityScheme(
    securityScheme: 'passport',
    type: 'oauth2',
    description: 'OAuth2 password grant issued by POST /oauth/token.',
    flows: [
        new OA\Flow(
            flow: 'password',
            tokenUrl: '/oauth/token',
            refreshUrl: '/oauth/token',
            scopes: [],
        ),
    ],
)]
class MovieController extends Controller
{
    /**
     * The front page of the API: the movies this server holds, most popular first.
     */
    #[OA\Get(
        path: '/api/movies',
        summary: 'List the movies of the front page',
        operationId: 'listMovies',
        tags: ['Movies'],
        parameters: [
            new OA\QueryParameter(parameter: 'page', description: 'The page to read.', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\QueryParameter(parameter: 'perPage', description: 'Rows per page, 20 by default.', schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The most popular movies available on this server.', ref: '#/components/schemas/MovieSummaryCollection'),
        ],
    )]
    public function index(PaginationRequest $request): ResourceCollection
    {
        $movies = Movie::query()
            ->with('sourceEntries')
            ->select('movies.*')
            ->addSelect(DB::raw('(SELECT MAX(sources.popularity) FROM sources WHERE sources.imdb_id = movies.imdb_id) AS popularity'))
            ->orderByRaw('(SELECT MAX(sources.popularity) FROM sources WHERE sources.imdb_id = movies.imdb_id) DESC NULLS LAST')
            ->orderBy('movies.id')
            ->paginate($request->perPage());

        return MovieSummaryResource::collection($movies);
    }

    /**
     * Everything this server has collected about one movie.
     */
    #[OA\Get(
        path: '/api/movies/{movie}',
        summary: 'Read a movie with the information collected about it',
        operationId: 'showMovie',
        tags: ['Movies'],
        security: [['passport' => []]],
        parameters: [
            new OA\PathParameter(parameter: 'movie', name: 'movie', description: 'The id of the movie.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The movie.', ref: '#/components/schemas/Movie'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 404, description: 'Unknown movie.'),
        ],
    )]
    public function show(Movie $movie): MovieResource
    {
        $movie->load(['sourceEntries', 'movieData']);

        return new MovieResource($movie->loadCount('comments'));
    }

    /**
     * What this API is and where its documentation lives.
     */
    #[OA\Get(
        path: '/api',
        summary: 'Describe the API',
        operationId: 'describeApi',
        tags: ['Movies'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The API itself.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Hypertube'),
                    new OA\Property(property: 'documentation', type: 'string', format: 'uri'),
                ]),
            ),
        ],
    )]
    public function describe(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'documentation' => url('api/documentation'),
        ]);
    }
}
