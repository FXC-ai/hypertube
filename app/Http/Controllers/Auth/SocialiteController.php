<?php

namespace App\Http\Controllers\Auth;

use App\Actions\DownloadProfilePicture;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class SocialiteController
{
    public function __construct(
        private readonly DownloadProfilePicture $downloadProfilePicture,
    ) {}

    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    private function generateUsername(string $provider, SocialiteUser $socialUser): string
    {
        $source = $socialUser->getNickname()
            ?: $socialUser->getName()
            ?: Str::before((string) $socialUser->getEmail(), '@');

        $baseUsername = Str::of($source)
            ->lower()
            ->slug()
            ->limit(255, '')
            ->toString();

        if ($baseUsername === '') {
            $baseUsername = 'user';
        }

        if (User::where('username', $baseUsername)->doesntExist()) {
            return $baseUsername;
        }

        $suffix = substr(
            hash('sha256', $provider.':'.$socialUser->getId()),
            0,
            3,
        );

        return Str::limit(
            $baseUsername,
            255 - strlen($suffix) - 1,
            '',
        ).'-'.$suffix;
    }

    private function downloadProfilepicture(?string $url): ?string
    {
        return ($this->downloadProfilePicture)($url);
    }

    protected function findOrCreateUser(string $provider, SocialiteUser $socialUser): User
    {
        // An account of this provider is already known: its tokens are refreshed and the
        // user it belongs to is the one signing in.
        $account = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($account) {
            $account->update([
                'token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
            ]);

            return $account->user;
        }

        // First visit through this provider: the account of that email address is either
        // found, or created with a username built from what the provider answers with.
        return DB::transaction(function () use ($provider, $socialUser): User {
            $user = User::firstOrCreate(
                ['email' => $socialUser->getEmail()],
                [
                    'username' => $this->generateUsername($provider, $socialUser),
                    'email_verified_at' => now(),
                ]
            );

            // A brand new account has its email verified by the provider itself, and its
            // picture is copied on this server.
            if ($user->wasRecentlyCreated) {
                $user->markEmailAsVerified();
                $user->update(['profilepicture' => $this->downloadProfilepicture($socialUser->getAvatar())]);
            }

            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar' => $socialUser->getAvatar(),
                'token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
            ]);

            return $user;
        });
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        // The provider refused the request: the user is sent back to the login page.
        $providerError = $request->query('error');

        if (is_string($providerError) && $providerError !== '') {
            Log::warning('OAuth authorization was rejected by the provider.', [
                'provider' => $provider,
                'error' => in_array($providerError, [
                    'access_denied',
                    'server_error',
                    'temporarily_unavailable',
                ], true) ? $providerError : 'unknown',
            ]);

            return redirect()->route('login')->withErrors([
                'social' => 'The external sign in request was cancelled or refused.',
            ]);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();

            // Both strategies are OAuth2, so the provider answers with a `Two\User`; the
            // tokens of that answer are what gets stored.
            if (! $socialUser instanceof SocialiteUser) {
                return redirect()->route('login')->withErrors([
                    'social' => 'External sign in is temporarily unavailable. Please try again.',
                ]);
            }

            if ($socialUser->getEmail() == null) {
                return redirect()->route('login')->withErrors(
                    [
                        'social' => 'That provider did not share an email address, so no account can be opened.',
                    ]
                );
            }

            $user = $this->findOrCreateUser($provider, $socialUser);

            Auth::login($user, remember: true);
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'social' => 'That sign in attempt expired. Please try again.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('login')->withErrors([
                'social' => 'External sign in is temporarily unavailable. Please try again.',
            ]);
        }

        return redirect()->intended(route('movies.index', absolute: false));
    }
}
