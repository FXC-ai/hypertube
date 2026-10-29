<?php

namespace App\Http\Controllers\Api;

use App\Actions\DownloadProfilePicture;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaginationRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    public function __construct(
        private readonly DownloadProfilePicture $downloadProfilePicture,
    ) {}

    /**
     * Every account of this instance, one page at a time.
     */
    #[OA\Get(
        path: '/api/users',
        summary: 'List the users',
        operationId: 'listUsers',
        tags: ['Users'],
        security: [['passport' => []]],
        parameters: [
            new OA\QueryParameter(parameter: 'page', name: 'page', description: 'The page to read.', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\QueryParameter(parameter: 'perPage', name: 'perPage', description: 'Rows per page, 20 by default.', schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The accounts of this instance.', ref: '#/components/schemas/UserCollection'),
            new OA\Response(response: 401, description: 'No valid access token.'),
        ],
    )]
    public function index(PaginationRequest $request): ResourceCollection
    {
        return UserResource::collection(
            User::query()->orderBy('username')->orderBy('id')->paginate($request->perPage())
        );
    }

    /**
     * One profile: anyone may read it, the email address only by its owner.
     */
    #[OA\Get(
        path: '/api/users/{user}',
        summary: 'Read a profile',
        operationId: 'showUser',
        tags: ['Users'],
        security: [['passport' => []]],
        parameters: [
            new OA\PathParameter(name: 'user', description: 'The id of the user.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The profile. The email address is only there for its owner.', ref: '#/components/schemas/User'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 403, description: 'This profile may not be read.'),
            new OA\Response(response: 404, description: 'Unknown user.'),
        ],
    )]
    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user);
    }

    /**
     * Patch a profile, which only its owner may do.
     */
    #[OA\Patch(
        path: '/api/users/{user}',
        summary: 'Update one of your own profiles',
        operationId: 'updateUser',
        tags: ['Users'],
        security: [['passport' => []]],
        parameters: [
            new OA\PathParameter(name: 'user', description: 'The id of the user, which must be yours.', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Only the fields sent are written. A changed email has to be verified again.',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'username', type: 'string', maxLength: 255),
                new OA\Property(property: 'firstname', type: 'string', maxLength: 255),
                new OA\Property(property: 'lastname', type: 'string', maxLength: 255),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string', minLength: 8, description: 'Hashed before being stored.'),
                new OA\Property(property: 'profilepicture', type: 'string', format: 'uri', description: 'A picture fetched from this link and stored on this server.'),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated profile.', ref: '#/components/schemas/User'),
            new OA\Response(response: 401, description: 'No valid access token.'),
            new OA\Response(response: 403, description: 'A profile may only be patched by its owner.'),
            new OA\Response(response: 404, description: 'Unknown user.'),
            new OA\Response(response: 422, description: 'One of the sent fields is invalid.'),
        ],
    )]
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $changes = $request->validated();

        if (array_key_exists('profilepicture', $changes)) {
            $stored = ($this->downloadProfilePicture)($changes['profilepicture']);

            if ($stored === null) {
                throw ValidationException::withMessages([
                    'profilepicture' => __('That picture could not be fetched from this link.'),
                ]);
            }

            $changes['profilepicture'] = $stored;
        }

        $user->fill($changes);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return new UserResource($user);
    }
}
