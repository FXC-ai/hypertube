<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'username', type: 'string'),
        new OA\Property(property: 'profilepicture', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'firstname', type: 'string', nullable: true),
        new OA\Property(property: 'lastname', type: 'string', nullable: true),
        new OA\Property(property: 'preferredlanguage', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email', description: 'Only sent to the owner of the profile.'),
    ],
)]
#[OA\Schema(
    schema: 'UserCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class UserResource extends JsonResource
{
    /**
     * The email address is only ever readable by the owner of the profile.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;
        $isOwner = $request->user()?->id === $profile->id;

        return [
            'id' => $profile->id,
            'username' => $profile->username,
            'profilepicture' => $profile->profilePictureUrl(),
            'firstname' => $profile->firstname,
            'lastname' => $profile->lastname,
            'preferredlanguage' => $profile->preferredlanguage?->value,
            'email' => $this->when($isOwner, $profile->email),
        ];
    }
}
