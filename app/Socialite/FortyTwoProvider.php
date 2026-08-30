<?php

namespace App\Socialite;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

class FortytwoProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopes = ['public'];

    protected $scopeSeparator = ' ';

    protected function getAuthUrl($state)
    {
        Log::channel('my_debug')->debug('getAuthUrl state = ', [$state]);

        return $this->buildAuthUrlFromBase('https://api.intra.42.fr/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        Log::channel('my_debug')->debug('getTokenUrl ', ['executed']);

        return 'https://api.intra.42.fr/oauth/token';
    }

    protected function getUserByToken($token): array
    {
        Log::channel('my_debug')->debug('getUserByToken ', [$token]);

        $response = $this->getHttpClient()->get(
            'https://api.intra.42.fr/v2/me',
            [
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
            ],
        );

        Log::channel('my_debug')->debug('getUserByToken response = ', [$response]);

        return json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    protected function mapUserToObject(array $user): User
    {
        Log::channel('my_debug')->debug('mapUserToObject user = ', [$user]);

        $newUser = (new User)->setRaw($user)->map(
            [
                'id' => $user['id'],
                'username' => Arr::get($user, 'login'),
                // 'name' => Arr::get($user, 'displayname'),
                'email' => Arr::get($user, 'email'),
                'avatar' => Arr::get($user, 'image.link') ?? Arr::get($user, 'image.versions.medium'),
            ]

        );

        Log::channel('my_debug')->debug('mapUserToObject newUser = ', [$newUser]);

        return $newUser;
    }
}
