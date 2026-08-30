<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\ProfilepictureRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;



use App\Models\User;
use App\Models\SocialAccount;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use phpDocumentor\Reflection\PseudoTypes\LowercaseString;
use Throwable;

class SocialiteController extends Controller
{
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
            hash('sha256', $provider . ':' . $socialUser->getId()),
            0,
            3,
        );

        return Str::limit(
            $baseUsername,
            255 - strlen($suffix) - 1,
            '',
        ) . '-' . $suffix;
    }

    private function downloadProfilepicture(?string $url): ?string
    {
        if ($url === null || $url === '') {
            Log::channel('my_debug')->debug('Return null: URL is empty', []);
            return null;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->get($url);
        } catch (Throwable) {
            Log::channel('my_debug')->debug('Return null: HTTP request threw an exception', []);
            return null;
        }

        if (! $response->successful()) {
            Log::channel('my_debug')->debug('Return null: HTTP response was unsuccessful', []);
            return null;
        }

        $contents = $response->body();

        if ($contents === '' || strlen($contents) > 2 * 1024 * 1024) {
            Log::channel('my_debug')->debug('Return null: image is empty or larger than 2 MB', []);
            return null;
        }

        $imageInformation = getimagesizefromstring($contents);

        if ($imageInformation === false) {
            Log::channel('my_debug')->debug('Return null: invalid image contents', []);
            return null;
        }

        [$width, $height] = $imageInformation;

        if ($width > 4000 || $height > 4000) {
            Log::channel('my_debug')->debug('Return null: image dimensions exceed 2000 px', []);
            return null;
        }

        $extension = match ($imageInformation['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };

        if ($extension === null) {
            Log::channel('my_debug')->debug('Return null: unsupported image MIME type', []);
            return null;
        }

        $path = 'avatars/' . Str::uuid() . '.' . $extension;
        Log::channel('my_debug')->debug('Return result of avatar storage', []);

        return Storage::disk('public')->put($path, $contents)
            ? $path
            : null;
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
                    //'profilepicture' => $this->downloadProfilepicture($socialUser->getAvatar()),
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

    public function callback(string $provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'social' => 'That sign in attempt expired. Please try again.',
            ]);
        }

        $user = $this->findOrCreateUser($provider, $socialUser);

        Auth::login($user, remember: true);

        return redirect()->intended('/dashboard');
    }
}
