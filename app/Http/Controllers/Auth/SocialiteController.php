<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Log;


class SocialiteController extends Controller
{
    /**
     * Fournisseurs pris en charge. (Sécurité pour éviter que quelqu'un tape /auth/bidule).
     */
    protected array $providers = ['github'];

    /**
     * Rediriger vers le fournisseur OAuth (L'étape 1 du flux).
     */
    public function redirect(string $provider)
    {
        if (! in_array($provider, $this->providers)) {
            abort(404);
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Gérer le callback (retour) OAuth (Les étapes 3, 4 et 5 du flux).
     */
    public function callback(string $provider)
    {
        if (! in_array($provider, $this->providers)) {
            abort(404);
        }

        try {
            // Récupérer les données depuis l'API de Github/Google
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            // Si l'utilisateur a refusé ou qu'il y a eu un bug
            return redirect()->route('login')
                ->with('error', 'Impossible de s\'authentifier avec ' . ucfirst($provider));
        }

        Log::channel('my_debug')->debug('Social user ', [$socialUser]);
        Log::channel('my_debug')->debug('Username = ', [$socialUser->getNickname()]);
        Log::channel('my_debug')->debug('Email = ', [$socialUser->getEmail()]);
        Log::channel('my_debug')->debug('Avatar = ', [$socialUser->getAvatar()]);
        Log::channel('my_debug')->debug('Name = ', [$socialUser->getName()]);
        Log::channel('my_debug')->debug('Id = ', [$socialUser->getId()]);
        // updateOrCreate va :
        // 1. Chercher un User avec le bon 'provider' et 'provider_id'
        // 2. Si non trouvé, le créer avec les informations du deuxième tableau
        $user = User::updateOrCreate(
            [
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
            ],
            [
                'name' => $socialUser->getName(),
                'email' => $socialUser->getEmail(),
                'avatar' => $socialUser->getAvatar(),
                'provider_token' => $socialUser->token,
                'provider_refresh_token' => $socialUser->refreshToken, // Utile pour taper dans les API
                'password' => bcrypt(Str::random(24)),  // Mot de passe aléatoire car géré par GitHub
            ]
        );

        // Connecter manuellement l'utilisateur dans Laravel (avec le cookie Remember Me)
        Auth::login($user, remember: true);

        return redirect()->intended('/dashboard');
    }
}
