# Durcir la gestion des erreurs OAuth

## Objectif

Le callback OAuth actuel ne transforme en erreur utilisateur que `InvalidStateException`. Un refus chez GitHub ou 42, une panne réseau, une réponse invalide du fournisseur ou une exception pendant la création du compte peut donc remonter jusqu'à Laravel et produire une réponse HTTP 500.

Ce point est bloquant pour Hypertube :

- le sujet, section **III.1 User Interface**, exige une inscription et une connexion avec au moins deux stratégies OAuth, dont 42 ;
- la grille, section **Users / User login**, vérifie ces deux stratégies ;
- les préliminaires de la grille considèrent toute réponse HTTP 500 à 599 comme un crash.

L'objectif est de conserver les détails techniques dans les logs, de ne montrer aucun secret au visiteur et de toujours ramener celui-ci vers la page de connexion avec un message anglais exploitable.

Les exemples ci-dessous correspondent à Laravel Socialite 5.30 et aux conventions actuelles du projet.

## 1. Distinguer les scénarios

Le callback doit traiter séparément trois catégories :

1. Le fournisseur renvoie un paramètre `error`, par exemple `access_denied` quand l'utilisateur refuse l'autorisation.
2. Socialite rejette le `state` de session avec `InvalidStateException`. Cela indique généralement une tentative expirée, une session perdue ou un callback qui ne correspond pas au départ de connexion.
3. Une autre exception survient pendant l'échange OAuth ou la connexion locale : indisponibilité du fournisseur, délai réseau, réponse incorrecte, contrainte de base de données, etc.

Un refus utilisateur n'est donc pas synonyme d'`InvalidStateException`. Le test actuel nommé `sign in is refused when OAuth authorization is cancelled` simule seulement un état invalide et devrait être renommé en conséquence.

## 2. Modifier le callback

Dans `app/Http/Controllers/Auth/SocialiteController.php`, ajouter les imports suivants :

```php
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
```

`Throwable` et `Log` sont déjà importés dans ce contrôleur.

Remplacer ensuite la méthode `callback` par cette version :

```php
public function callback(Request $request, string $provider): RedirectResponse
{
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

        if ($socialUser->getEmail() === null) {
            return redirect()->route('login')->withErrors([
                'social' => 'No email address was provided by this account.',
            ]);
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
```

### Pourquoi ce découpage ?

- Le paramètre `error` est lu avant d'appeler `Socialite::user()`, car aucun code d'autorisation utilisable n'est fourni après un refus.
- La valeur envoyée par le fournisseur n'est pas affichée au visiteur.
- Seules des valeurs d'erreur connues sont inscrites dans le contexte du log. Les paramètres `code`, token, `error_description` et autres données OAuth ne doivent jamais être journalisés.
- `InvalidStateException` conserve un message spécifique, car relancer le parcours suffit normalement.
- Le dernier `catch` inclut l'échange distant et `findOrCreateUser()`. La transaction existante annulera les écritures incomplètes si la création locale échoue.
- `report($exception)` transmet l'exception au gestionnaire Laravel avant d'afficher un message neutre. Il ne faut pas remplacer cet appel par un log contenant les tokens ou la réponse OAuth complète.
- Le nom du fournisseur reste limité à `github` et `fortytwo` par le `whereIn` déjà présent dans `routes/web.php`.

Ne pas utiliser `stateless()` pour faire disparaître `InvalidStateException` : ce parcours utilise une session web et la vérification du `state` est une protection OAuth à conserver.

## 3. Mettre les tests Pest à jour

Les tests appartiennent à `tests/Feature/Auth/SocialAccountTest.php`.

### État OAuth invalide

Conserver le test existant qui lance `InvalidStateException`, mais le renommer pour décrire ce qu'il vérifie réellement :

```php
test(
    'sign in is refused when the OAuth state is invalid',
    function (string $provider) {
        $socialiteProvider = Mockery::mock(Provider::class);

        $socialiteProvider
            ->shouldReceive('user')
            ->once()
            ->andThrow(new InvalidStateException);

        Socialite::shouldReceive('driver')
            ->once()
            ->with($provider)
            ->andReturn($socialiteProvider);

        $response = $this->get(route('socialite.callback', [
            'provider' => $provider,
        ]));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'social' => 'That sign in attempt expired. Please try again.',
            ]);
    },
)->with([
    'GitHub' => ['github'],
    '42' => ['fortytwo'],
]);
```

### Refus explicite chez le fournisseur

Ajouter un vrai test de refus. Il vérifie notamment que Socialite ne tente pas d'échanger un code absent :

```php
test(
    'sign in returns safely when OAuth authorization is refused',
    function (string $provider) {
        Socialite::shouldReceive('driver')->never();

        $response = $this->get(route('socialite.callback', [
            'provider' => $provider,
            'error' => 'access_denied',
        ]));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'social' => 'The external sign in request was cancelled or refused.',
            ]);
    },
)->with([
    'GitHub' => ['github'],
    '42' => ['fortytwo'],
]);
```

### Erreur inattendue pendant l'échange

Ajouter ce test du filet de sécurité. Le fichier de test n'ayant pas de namespace, `RuntimeException` peut être utilisé directement sans import :

```php
test(
    'sign in returns safely when the OAuth provider fails',
    function (string $provider) {
        $socialiteProvider = Mockery::mock(Provider::class);

        $socialiteProvider
            ->shouldReceive('user')
            ->once()
            ->andThrow(new RuntimeException('Provider unavailable.'));

        Socialite::shouldReceive('driver')
            ->once()
            ->with($provider)
            ->andReturn($socialiteProvider);

        $response = $this->get(route('socialite.callback', [
            'provider' => $provider,
        ]));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'social' => 'External sign in is temporarily unavailable. Please try again.',
            ]);
    },
)->with([
    'GitHub' => ['github'],
    '42' => ['fortytwo'],
]);
```

Ce dernier test emploie `RuntimeException` pour vérifier le comportement de repli indépendamment de l'implémentation HTTP interne de Socialite. Les tests de succès déjà présents protègent le parcours nominal pour GitHub et 42.

## 4. Formater et vérifier

Après les changements PHP, lancer Pint comme requis par le projet :

```bash
vendor/bin/pint --dirty --format agent
```

Exécuter ensuite uniquement la suite concernée :

```bash
php artisan test --compact tests/Feature/Auth/SocialAccountTest.php
```

Le résultat attendu est :

- les connexions GitHub et 42 continuent de fonctionner ;
- une adresse email absente reste refusée proprement ;
- un `state` invalide redirige vers la connexion ;
- un refus `access_denied` redirige vers la connexion sans appeler l'échange OAuth ;
- une exception du fournisseur est signalée dans les logs, sans provoquer de HTTP 500 côté visiteur ;
- aucun utilisateur ni compte social partiel n'est conservé après un échec.

## 5. Vérification manuelle

En environnement local, démarrer une connexion GitHub puis une connexion 42 et cliquer sur le bouton de refus chez chaque fournisseur. Dans les deux cas :

1. le navigateur doit revenir sur la page de connexion ;
2. le message doit être compréhensible et en anglais ;
3. la réponse ne doit pas être un HTTP 500 ;
4. aucune session authentifiée ne doit être créée ;
5. aucun token, code OAuth ou secret ne doit apparaître dans la page ou dans les logs.

Simuler ensuite une indisponibilité du fournisseur seulement dans un environnement de développement contrôlé. Vérifier que l'exception est bien reportée côté serveur et que le visiteur reçoit le message générique. Ne jamais modifier les identifiants OAuth de production pour réaliser ce test.
