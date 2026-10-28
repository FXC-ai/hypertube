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
use Throwable;

class SocialiteController
{
    public function __construct(
        private readonly DownloadProfilePicture $downloadProfilePicture,
    ) {}

    public function redirect(string $provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    private function generateUsername(string $provider, $socialUser): string
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

    protected function findOrCreateUser(string $provider, $socialUser): User
    {

        // Recherche si un social account existe déjà
        $account = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        // si un solcial account existe alors on rafraichit les tokens
        // puis on renvoit le user relié a ce ce social account
        if ($account) {
            $account->update([
                'token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
            ]);

            return $account->user;
        }

        // Si c'est la premiere fois que l'utilisateur se connecte avec un compte externe
        // on crée un nouveau user
        return DB::transaction(function () use ($provider, $socialUser) {

            // ici on gere le cas ou l'utilisateur a déjà un compte
            $user = User::firstOrCreate(
                ['email' => $socialUser->getEmail()],
                [
                    'username' => $this->generateUsername($provider, $socialUser),
                    // 'profilepicture' => $this->downloadProfilepicture($socialUser->getAvatar()),
                    'email_verified_at' => now(),
                ]
            );

            // Si le user n'avait pas de compte avant on marque son email comme verfied
            // et on download son avatar
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

    public function callback(Request $request, string $provider)
    {
        Log::channel('my_debug')->info('callback appelé');
        // on vérifie si le service externe a renvoyé une erreur
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
            if ($socialUser->getEmail() == null) {
                return redirect()->route('login')->withErrors(
                    [
                        'social' => 'No mail adress had been provided to this provider account.',
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
