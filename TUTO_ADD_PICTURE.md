# Ajouter une photo de profil facultative à l'inscription

## Objectif et conformité 42

Le sujet, section **III.1 — User Interface**, impose la gestion d'une photo de profil et la possibilité de la modifier. La grille, section **Users / Creation and management of user account**, vérifie en plus que la photo est proposée lors de la création du compte. En parallèle, le sujet dit que l'inscription demande « au moins » l'email, le nom d'utilisateur, le prénom, le nom et le mot de passe : il n'impose donc pas de bloquer l'inscription en l'absence de photo.

L'implémentation retenue est la suivante :

- le champ est visible dès l'inscription ;
- l'utilisateur peut s'inscrire sans choisir de fichier ;
- `profilepicture` reste alors à `null` et l'interface affiche les initiales grâce à `AvatarFallback` ;
- lorsqu'un fichier est fourni, le serveur contrôle réellement son contenu, son format, sa taille et ses dimensions avant de le stocker ;
- Laravel génère le nom du fichier : le nom envoyé par l'utilisateur n'est jamais réutilisé.

La validation HTML (`accept`) améliore seulement l'expérience utilisateur. **La sécurité repose obligatoirement sur la validation Laravel côté serveur.**

## État actuel du projet

Aucune migration n'est nécessaire : la colonne `users.profilepicture` existe déjà, elle est nullable, et `User` autorise déjà son remplissage. Le disque `public`, l'affichage de secours avec les initiales et la modification de la photo depuis le profil existent également.

Les changements concernent donc uniquement :

1. la règle réutilisable de validation de la photo ;
2. l'action Fortify qui crée l'utilisateur ;
3. le formulaire React/Inertia d'inscription ;
4. les tests Pest d'inscription.

## 1. Rendre la règle de photo réutilisable

Dans `app/Concerns/ProfilepictureRules.php`, remplacez `profilepictureRules()` par une méthode qui peut rendre le fichier obligatoire ou facultatif :

```php
protected function profilepictureRules(bool $required = true): array
{
    return [
        $required ? 'required' : 'nullable',
        File::image()
            ->types(['jpg', 'jpeg', 'png', 'webp', 'gif'])
            ->max('2mb')
            ->dimensions(
                Rule::dimensions()
                    ->maxWidth(4000)
                    ->maxHeight(4000),
            ),
    ];
}
```

Le paramètre vaut `true` par défaut. Le formulaire des réglages existant, qui appelle la méthode sans argument, continue donc d'exiger une nouvelle image lors d'une mise à jour. L'inscription appellera la même méthode avec `required: false`.

Ces règles forment une défense en profondeur :

- `nullable` permet l'absence de fichier ;
- `File::image()` vérifie que le contenu est une image lisible ;
- `types(...)` limite explicitement les types acceptés et exclut notamment SVG, PHP, HTML et JavaScript ;
- `max('2mb')` limite la charge envoyée et l'espace disque consommé ;
- `dimensions(...)` refuse les images démesurées, même si elles sont très compressées.

Laravel détermine le type MIME depuis le contenu du fichier, pas uniquement depuis son extension. Renommer un script en `avatar.jpg` ne suffit donc pas à passer la validation.

## 2. Valider et stocker la photo pendant l'inscription

Dans `app/Actions/Fortify/CreateNewUser.php` :

1. ajoutez le trait `ProfilepictureRules` ;
2. validez `profilepicture` comme champ facultatif ;
3. stockez uniquement le fichier déjà validé sur le disque `public` ;
4. enregistrez uniquement le chemin généré dans la base ;
5. supprimez le fichier si la création de l'utilisateur échoue, afin de ne pas laisser de fichier orphelin.

Le fichier complet peut devenir :

```php
<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfilepictureRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use RuntimeException;
use Throwable;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfilepictureRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'profilepicture' => $this->profilepictureRules(required: false),
            'password' => $this->passwordRules(),
        ])->validate();

        $profilepicturePath = null;

        try {
            if (($validated['profilepicture'] ?? null) instanceof UploadedFile) {
                $profilepicturePath = $validated['profilepicture']->store('avatars', 'public');

                if ($profilepicturePath === false) {
                    throw new RuntimeException('The profile picture could not be stored.');
                }
            }

            return DB::transaction(fn (): User => User::create([
                'username' => $validated['username'],
                'email' => $validated['email'],
                'firstname' => $validated['firstname'],
                'lastname' => $validated['lastname'],
                'password' => $validated['password'],
                'profilepicture' => $profilepicturePath,
            ]));
        } catch (Throwable $exception) {
            if ($profilepicturePath !== null) {
                Storage::disk('public')->delete($profilepicturePath);
            }

            throw $exception;
        }
    }
}
```

Points importants :

- il faut utiliser `$validated`, jamais directement les valeurs non validées de `$input` ;
- `store('avatars', 'public')` produit un nom aléatoire et évite les collisions ainsi que les noms de fichiers malveillants ;
- seul un chemin comme `avatars/abc123.jpg` est enregistré dans `users.profilepicture`, jamais le contenu binaire ;
- le cast `hashed` déjà présent sur `User::password` continue de protéger le mot de passe ;
- si aucune photo n'est envoyée, aucun accès à `profilepicture` n'est tenté et la valeur enregistrée est `null`.

## 3. Ajouter le champ au formulaire React/Inertia

Dans `resources/js/pages/auth/register.tsx`, ajoutez ce bloc avant le mot de passe :

```tsx
<div className="grid gap-2">
    <Label htmlFor="profilepicture">
        Profile picture <span className="text-muted-foreground">(optional)</span>
    </Label>
    <Input
        id="profilepicture"
        type="file"
        name="profilepicture"
        accept="image/jpeg,image/png,image/webp,image/gif"
        tabIndex={3}
        aria-describedby="profilepicture-help"
    />
    <p id="profilepicture-help" className="text-sm text-muted-foreground">
        JPG, PNG, WebP or GIF. Maximum 2 MB and 4000 × 4000 pixels.
    </p>
    <InputError message={errors.profilepicture} />
</div>
```

Ne mettez pas l'attribut `required` sur ce champ. Décalez ensuite les `tabIndex` des champs suivants pour conserver un ordre de navigation cohérent.

Il n'est pas nécessaire d'ajouter manuellement `encType="multipart/form-data"` ni de convertir le fichier : avec Inertia 3, le composant `<Form>` détecte le fichier et transforme automatiquement la requête en `FormData`.

L'attribut `accept` ne remplace jamais les règles PHP : il peut être contourné en envoyant directement une requête HTTP.

## 4. Rendre les fichiers publics accessibles

Le projet affiche les photos avec `/storage/{chemin}`. Sur une nouvelle installation, créez une seule fois le lien symbolique Laravel :

```bash
php artisan storage:link
```

Les fichiers seront physiquement placés dans `storage/app/public/avatars` et servis via `public/storage`. Ne stockez pas les uploads directement dans `public/` et ne leur donnez jamais leur nom d'origine.

## 5. Ajouter les tests Pest

La grille d'évaluation considère l'absence de validation des uploads comme éliminatoire. Il faut donc démontrer à la fois que le champ est facultatif et qu'un fichier hostile est refusé.

Dans `tests/Feature/Auth/RegistrationTest.php`, ajoutez les imports suivants :

```php
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

Conservez le test existant sans photo et complétez-le avec ces assertions :

```php
Storage::fake('public');

// Soumission existante sans profilepicture...

$user = User::where('email', 'test@example.com')->firstOrFail();

expect($user->profilepicture)->toBeNull();
expect(Storage::disk('public')->allFiles())->toBeEmpty();
```

Ajoutez ensuite un test d'upload valide :

```php
test('a new user may register with a profile picture', function () {
    Storage::fake('public');

    $avatar = new UploadedFile(
        base_path('tests/Fixtures/avatar.jpeg'),
        'avatar.jpg',
        'image/jpeg',
        null,
        true,
    );

    $response = $this->post(route('register.store'), [
        'username' => 'picture user',
        'email' => 'picture@example.com',
        'firstname' => 'Picture',
        'lastname' => 'User',
        'password' => 'password',
        'password_confirmation' => 'password',
        'profilepicture' => $avatar,
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('movies.index', absolute: false));

    $user = User::where('email', 'picture@example.com')->firstOrFail();

    expect($user->profilepicture)->not->toBeNull();
    Storage::disk('public')->assertExists($user->profilepicture);
});
```

Ajoutez un test qui prouve qu'une extension trompeuse ne contourne pas le contrôle du contenu :

```php
test('a new user cannot upload a non-image as a profile picture', function () {
    Storage::fake('public');

    $response = $this->post(route('register.store'), [
        'username' => 'unsafe user',
        'email' => 'unsafe@example.com',
        'firstname' => 'Unsafe',
        'lastname' => 'User',
        'password' => 'password',
        'password_confirmation' => 'password',
        'profilepicture' => UploadedFile::fake()->createWithContent(
            'avatar.jpg',
            '<?php echo "unsafe"; ?>',
        ),
    ]);

    $response->assertSessionHasErrors('profilepicture');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'unsafe@example.com']);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});
```

Ajoutez enfin les limites de poids et de dimensions. Ces tests utilisent GD, déjà nécessaire aux tests d'images existants du projet :

```php
test('a registration profile picture may not exceed two megabytes', function () {
    Storage::fake('public');

    $response = $this->post(route('register.store'), [
        'username' => 'large file user',
        'email' => 'large-file@example.com',
        'firstname' => 'Large',
        'lastname' => 'File',
        'password' => 'password',
        'password_confirmation' => 'password',
        'profilepicture' => UploadedFile::fake()->image('avatar.jpg')->size(2049),
    ]);

    $response->assertSessionHasErrors('profilepicture');
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a registration profile picture may not exceed 4000 pixels', function () {
    Storage::fake('public');

    $response = $this->post(route('register.store'), [
        'username' => 'large image user',
        'email' => 'large-image@example.com',
        'firstname' => 'Large',
        'lastname' => 'Image',
        'password' => 'password',
        'password_confirmation' => 'password',
        'profilepicture' => UploadedFile::fake()->image('avatar.jpg', 4001, 4001),
    ]);

    $response->assertSessionHasErrors('profilepicture');
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});
```

## 6. Formater et vérifier

Après l'implémentation, exécutez uniquement les vérifications concernées :

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Auth/RegistrationTest.php tests/Feature/Settings/ProfilePictureTest.php
npm run types:check
```

Le test `ProfilePictureTest.php` est important : il confirme que le passage de la règle à une méthode paramétrable n'a pas rendu facultative la photo dans le formulaire séparé de mise à jour.

## Checklist manuelle avant l'évaluation

- L'inscription sans photo réussit et affiche les initiales à la place de l'image.
- L'inscription avec un JPG, PNG, WebP ou GIF valide réussit et la photo est visible après redirection.
- Un fichier texte, PHP, HTML ou SVG, même renommé en `.jpg`, est refusé.
- Une image de plus de 2 Mo est refusée.
- Une image dépassant 4000 pixels en largeur ou hauteur est refusée.
- Une erreur de validation ne crée ni utilisateur ni fichier sur le disque.
- Le navigateur n'affiche aucune erreur ou alerte dans sa console.

Avec ces contrôles, le champ reste réellement facultatif tout en répondant au contrôle de création de compte de la grille et à l'exigence générale de validation sécurisée des uploads du sujet.
