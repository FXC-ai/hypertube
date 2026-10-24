# Tutoriel : header, footer et remplacement du Dashboard

Ce tutoriel explique comment :

1. ajouter un header et un footer sur toutes les pages ;
2. supprimer la page et la route Dashboard ;
3. remplacer les liens Dashboard par des liens vers la bibliothèque de films.

Ces changements répondent au chapitre II du sujet Hypertube, qui exige au minimum un header, une section principale et un footer. Ils répondent également aux critères **Good practices / Look & feel** et **Mobile** de la grille d'évaluation.

## 1. Préparer le travail

Créer une branche dédiée et vérifier que le dépôt est propre :

```bash
git switch -c feature/add-footer-header
git status --short
```

Avant de modifier la navigation, repérer toutes les références au Dashboard :

```bash
rg -n "dashboard|Dashboard" app config routes resources/js tests
```

Dans l'état actuel du projet, ces références se trouvent notamment dans :

- `routes/web.php` ;
- `config/fortify.php` ;
- `app/Http/Controllers/Auth/SocialiteController.php` ;
- `resources/js/pages/dashboard.tsx` ;
- `resources/js/pages/welcome.tsx` ;
- `resources/js/components/app-sidebar.tsx` ;
- `resources/js/components/app-header.tsx` ;
- plusieurs tests d'authentification.

## 2. Créer un footer partagé

Créer `resources/js/components/site-footer.tsx` :

```tsx
import { Link } from '@inertiajs/react';
import { home } from '@/routes';

export function SiteFooter() {
    return (
        <footer className="mt-auto border-t border-border/70 bg-background">
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-2 px-4 py-6 text-center text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:text-left">
                <p>
                    &copy; {new Date().getFullYear()} Hypertube. Discover and
                    stream legally distributed movies.
                </p>

                <Link
                    href={home()}
                    className="font-medium text-foreground underline-offset-4 hover:underline"
                >
                    Home
                </Link>
            </div>
        </footer>
    );
}
```

Le footer utilise une disposition verticale sur mobile et horizontale à partir du breakpoint `sm`. La classe `mt-auto` permet de le maintenir en bas d'une page courte lorsque son parent utilise `flex min-h-screen flex-col`.

## 3. Créer un header public partagé

La zone authentifiée possède déjà `AppSidebarHeader`, mais les pages publiques et les pages d'authentification ont besoin d'un header cohérent.

Créer `resources/js/components/site-header.tsx` :

```tsx
import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import { home, login, register } from '@/routes';
import { index as moviesIndex } from '@/routes/movies';

export function SiteHeader() {
    const { auth } = usePage().props;

    return (
        <header className="border-b border-border/70 bg-background">
            <div className="mx-auto flex h-16 w-full max-w-7xl items-center justify-between gap-4 px-4">
                <Link href={home()} className="flex items-center" prefetch>
                    <AppLogo />
                </Link>

                <nav aria-label="Main navigation" className="flex items-center gap-2">
                    {auth.user ? (
                        <Button asChild>
                            <Link href={moviesIndex()} prefetch>
                                Movie library
                            </Link>
                        </Button>
                    ) : (
                        <>
                            <Button variant="ghost" asChild>
                                <Link href={login()}>Log in</Link>
                            </Button>
                            <Button asChild>
                                <Link href={register()}>Register</Link>
                            </Button>
                        </>
                    )}
                </nav>
            </div>
        </header>
    );
}
```

Le texte visible du site reste en anglais, conformément aux conventions du projet. Les liens utilisent les fonctions Wayfinder plutôt que des URL écrites en dur.

## 4. Ajouter le footer aux pages authentifiées

Les pages de films, d'utilisateurs et de paramètres passent par `AppLayout`, puis par `resources/js/layouts/app/app-sidebar-layout.tsx`.

Importer le footer :

```tsx
import { SiteFooter } from '@/components/site-footer';
```

Puis le placer après le contenu de la page :

```tsx
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
                <SiteFooter />
            </AppContent>
        </AppShell>
    );
}
```

`AppSidebarHeader` constitue déjà le header de cette zone. Il contient notamment le bouton d'ouverture de la sidebar sur mobile et le fil d'Ariane.

Le projet contient également `resources/js/layouts/app/app-header-layout.tsx`. Même s'il n'est pas le layout principal actuel, ajouter aussi `SiteFooter` après `AppContent` afin qu'une future page utilisant ce layout conserve la même structure.

## 5. Ajouter le header et le footer aux pages d'authentification

Toutes les pages `auth/*` utilisent `resources/js/layouts/auth/auth-simple-layout.tsx`. Ajouter les imports :

```tsx
import { SiteFooter } from '@/components/site-footer';
import { SiteHeader } from '@/components/site-header';
```

Réorganiser ensuite le layout autour d'un élément `<main>` :

```tsx
return (
    <div className="flex min-h-svh flex-col bg-background">
        <SiteHeader />

        <main className="flex flex-1 items-center justify-center p-6 md:p-10">
            <div className="w-full max-w-sm">
                {/* Conserver ici le logo, le titre et children existants. */}
            </div>
        </main>

        <SiteFooter />
    </div>
);
```

Cette modification couvre automatiquement la connexion, l'inscription, le mot de passe oublié, la réinitialisation du mot de passe et la vérification de l'adresse e-mail.

## 6. Ajouter le header et le footer à l'accueil

La page `resources/js/pages/welcome.tsx` n'utilise volontairement aucun layout global dans `resources/js/app.tsx`. Elle doit donc importer directement les composants :

```tsx
import { SiteFooter } from '@/components/site-footer';
import { SiteHeader } from '@/components/site-header';
```

Sa structure générale doit devenir :

```tsx
export default function Welcome() {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <Head title="Welcome" />
            <SiteHeader />

            <main className="flex flex-1 items-center">
                {/* Conserver ou remplacer ici le contenu principal. */}
            </main>

            <SiteFooter />
        </div>
    );
}
```

Profiter du contenu principal pour rendre le parcours évident : un visiteur doit voir immédiatement comment créer un compte, puis comprendre qu'il pourra rechercher un film dans la bibliothèque.

## 7. Supprimer le Dashboard

### Supprimer la route

Dans `routes/web.php`, retirer :

```php
Route::inertia('dashboard', 'dashboard')->name('dashboard');
```

La route `movies.index` reste protégée par les middlewares `auth` et `verified`, comme l'exige la section **Library / Access** de la grille d'évaluation.

### Supprimer la page

Supprimer :

```text
resources/js/pages/dashboard.tsx
```

### Supprimer l'ancien test

Supprimer `tests/Feature/DashboardTest.php`, puis créer un test de navigation, par exemple `tests/Feature/NavigationTest.php` :

```php
<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login when opening the movie library', function () {
    $this->get(route('movies.index'))->assertRedirect(route('login'));
});

test('authenticated users can visit the movie library', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('movies.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('movies/index'));
});

test('the removed dashboard page is not available', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertNotFound();
});
```

## 8. Remplacer les liens Dashboard par la bibliothèque

### Sidebar principale

Dans `resources/js/components/app-sidebar.tsx` :

1. retirer l'import de `dashboard` et l'icône `LayoutGrid` si elle n'est plus utilisée ;
2. renommer l'entrée `Video` en `Movie library` ;
3. supprimer l'entrée Dashboard ;
4. faire pointer le logo vers `index_movies()`.

Le début de la liste devient :

```tsx
const mainNavItems: NavItem[] = [
    {
        title: 'Movie library',
        href: index_movies(),
        icon: Film,
    },
    {
        title: 'Users',
        href: index_users(),
        icon: User,
    },
];
```

Et le lien du logo devient :

```tsx
<Link href={index_movies()} prefetch>
    <AppLogo />
</Link>
```

### Header alternatif

Dans `resources/js/components/app-header.tsx` :

- remplacer l'import `dashboard` par `index as moviesIndex` depuis `@/routes/movies` ;
- remplacer le titre `Dashboard` par `Movie library` ;
- utiliser l'icône `Film` ;
- faire pointer le logo et le bouton de recherche vers `moviesIndex()`.

### Page d'accueil

Dans `resources/js/pages/welcome.tsx`, remplacer le bouton Dashboard affiché aux utilisateurs connectés :

```tsx
<Link href={moviesIndex()}>
    Movie library
</Link>
```

## 9. Mettre à jour les redirections après authentification

La suppression de la route Dashboard impose de modifier toutes les redirections qui la ciblent.

Dans `config/fortify.php` :

```php
'home' => '/movies',
```

Dans `app/Http/Controllers/Auth/SocialiteController.php` :

```php
return redirect()->intended(route('movies.index', absolute: false));
```

Mettre ensuite à jour les assertions concernées dans :

- `tests/Feature/Auth/AuthenticationTest.php` ;
- `tests/Feature/Auth/RegistrationTest.php` ;
- `tests/Feature/Auth/EmailVerificationTest.php` ;
- `tests/Feature/Auth/VerificationNotificationTest.php` ;
- `tests/Feature/Auth/SocialAccountTest.php` ;
- `tests/Feature/Settings/UpdateAndAuthenticateTest.php`.

Exemple d'assertion :

```php
$response->assertRedirect(route('movies.index', absolute: false));
```

Conserver le suffixe `?verified=1` dans les tests qui vérifient ce paramètre :

```php
$response->assertRedirect(
    route('movies.index', absolute: false).'?verified=1'
);
```

## 10. Régénérer Wayfinder

Après la suppression de la route Dashboard, régénérer les fonctions TypeScript :

```bash
php artisan wayfinder:generate --with-form --no-interaction
```

L'option `--with-form` est importante dans ce projet, car plusieurs pages utilisent les helpers `.form()` générés par Wayfinder.

Vérifier ensuite qu'aucune référence applicative au Dashboard ne subsiste :

```bash
rg -n "dashboard|Dashboard" app config routes resources/js tests
```

La seule occurrence acceptable peut être le test qui confirme que l'ancienne URL `/dashboard` retourne désormais une réponse 404.

## 11. Formater et tester

Formater les fichiers PHP modifiés :

```bash
vendor/bin/pint --dirty --format agent
```

Formater les fichiers React concernés :

```bash
npx prettier --write \
    resources/js/components/site-header.tsx \
    resources/js/components/site-footer.tsx \
    resources/js/components/app-header.tsx \
    resources/js/components/app-sidebar.tsx \
    resources/js/layouts/app/app-header-layout.tsx \
    resources/js/layouts/app/app-sidebar-layout.tsx \
    resources/js/layouts/auth/auth-simple-layout.tsx \
    resources/js/pages/welcome.tsx
```

Exécuter les tests ciblés :

```bash
php artisan test --compact \
    tests/Feature/NavigationTest.php \
    tests/Feature/Auth \
    tests/Feature/Settings/UpdateAndAuthenticateTest.php
```

Vérifier ensuite le frontend :

```bash
npm run types:check
npm run lint:check
npm run build
```

## 12. Vérification manuelle

Tester au minimum les écrans suivants dans Chrome et Firefox, en vue desktop puis mobile :

- accueil en tant que visiteur ;
- inscription ;
- connexion ;
- bibliothèque de films ;
- fiche d'un film ;
- liste des utilisateurs ;
- paramètres du compte.

Pour chaque écran, vérifier :

- la présence d'un header, d'un contenu principal et d'un footer ;
- l'absence de défilement horizontal ;
- l'accès évident à l'inscription pour un visiteur ;
- l'accès évident à la bibliothèque pour un utilisateur connecté ;
- le fonctionnement du menu mobile ;
- l'absence d'erreurs et d'avertissements dans la console du navigateur ;
- l'absence de réponse HTTP 500 à 599.

Cette dernière vérification est indispensable : la grille d'évaluation considère une erreur dans la console ou une réponse serveur 5xx comme un crash.
