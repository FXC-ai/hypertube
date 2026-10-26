# Purger les films non visionnés depuis un mois

## Objectif et périmètre

Le sujet Hypertube, section **III.3 Video Part**, impose de conserver un film
téléchargé sur le serveur afin de le réutiliser, puis de le supprimer lorsqu’il
n’a pas été visionné depuis un mois. La grille d’évaluation reprend cette règle
dans **Video part > Saving videos**.

Dans ce projet, nous acceptons volontairement l’approximation suivante :

> ouvrir la page d’un film compte comme un visionnage.

Le champ `movies.last_watched_at` est donc actualisé à l’ouverture de la page.
Lorsqu’il est nul, `movies.updated_at` sert de date de référence. Cette seconde
date couvre notamment un film injecté par un seeder et jamais ouvert.

Ce tutoriel met en place :

1. un état temporaire `DownloadStatus::Purging` ;
2. une commande idempotente `movies:purge-stale` ;
3. une coordination PostgreSQL entre la purge et l’ouverture d’une page ;
4. une interface qui ne propose ni lecture ni préparation pendant la purge ;
5. un service Docker `scheduler` autonome, utilisant cron une fois par jour ;
6. les tests Pest qui protègent le streaming pendant le téléchargement.

Les extraits sont un guide d’implémentation. Avant de les appliquer, vérifier les
différences éventuelles avec les fichiers actuels et conserver leurs conventions.

## Invariant critique : ne pas attendre la fin du téléchargement

Le téléchargement torrent et la conversion HLS fonctionnent simultanément. Dès
que la conversion a publié assez de segments, le film doit pouvoir être lu, même
si le torrent continue à se télécharger.

Les combinaisons suivantes doivent rester valides :

| Download status | Conversion status | Lisible ? |
| --- | --- | --- |
| `downloading` | `playable` | oui |
| `completed` | `playable` | oui |
| `downloading` | `converted` | oui |
| `completed` | `converted` | oui |
| `purging` | `playable` ou `converted` | non |

La disponibilité ne doit donc **jamais** exiger
`download_status === completed`. La règle correcte est :

```text
conversion_status est playable ou converted
ET download_status n'est pas purging
```

Ce comportement devra être couvert par des tests avant d’implémenter la purge.

## Architecture retenue

Le cycle du téléchargement devient :

```text
pending -> downloading -> completed -> purging -> pending
```

La commande ne sélectionne comme nouveaux candidats que les films qui vérifient
toutes les conditions suivantes :

```text
download_status = completed
conversion_status IN (pending, failed, converted)
(last_watched_at ?? updated_at) <= maintenant - un mois
```

Les états `queued`, `converting` et `playable` sont exclus : ils représentent une
conversion en attente ou encore active. Un film déjà en `purging` est repris sans
réappliquer le critère d’ancienneté, afin de terminer une purge interrompue.

Pour chaque film :

```text
transaction PostgreSQL courte
    verrouiller la ligne
    recharger et revérifier les conditions
    passer download_status à purging
valider la transaction

supprimer le dossier hors transaction

si la suppression réussit
    réinitialiser la ligne
sinon
    conserver purging pour une prochaine tentative
```

Le statut `purging` ferme la fenêtre dangereuse pendant laquelle la base pourrait
annoncer un film lisible alors que son dossier est en train de disparaître.

---

## Étape 1 — Ajouter `DownloadStatus::Purging`

Modifier `app/Enums/DownloadStatus.php` :

```php
<?php

namespace App\Enums;

enum DownloadStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Completed = 'completed';
    case Failed = 'failed';
    case Unsupported = 'unsupported';
    case Purging = 'purging';
}
```

Aucune migration n’est nécessaire : `download_status` est actuellement une
colonne texte, sans contrainte SQL limitant ses valeurs.

### Adapter `Movie::isPlayable()`

Modifier `app/Models/Movie.php`. Ne pas demander un téléchargement terminé : le
film doit rester lisible pendant `downloading` dès que la conversion est
`playable`.

```php
public function isPlayable(): bool
{
    return $this->download_status !== DownloadStatus::Purging
        && in_array($this->conversion_status, [
            ConversionStatus::Playable,
            ConversionStatus::Converted,
        ], true);
}
```

Ajouter également le cast de `last_watched_at`, utile pour comparer proprement
les dates dans la commande :

```php
protected function casts(): array
{
    return [
        // casts déjà présents...
        'last_watched_at' => 'datetime',
    ];
}
```

Ne pas ajouter `Purging` à `ConversionStatus`. La conversion conserve son état ;
c’est le cycle de stockage du téléchargement qui indique que les fichiers sont
en cours de suppression.

---

## Étape 2 — Écrire d’abord les tests de disponibilité

Créer le test avec Artisan :

```bash
php artisan make:test --pest MoviePlayabilityTest --no-interaction
```

Dans le fichier généré, protéger au minimum ces quatre cas :

```php
<?php

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Models\Movie;

test('a playable movie stays playable while its torrent is downloading', function () {
    $movie = Movie::factory()->make([
        'download_status' => DownloadStatus::Downloading,
        'conversion_status' => ConversionStatus::Playable,
    ]);

    expect($movie->isPlayable())->toBeTrue();
});

test('a converted movie stays playable while its torrent is downloading', function () {
    $movie = Movie::factory()->make([
        'download_status' => DownloadStatus::Downloading,
        'conversion_status' => ConversionStatus::Converted,
    ]);

    expect($movie->isPlayable())->toBeTrue();
});

test('a playable movie is unavailable while its files are being purged', function () {
    $movie = Movie::factory()->make([
        'download_status' => DownloadStatus::Purging,
        'conversion_status' => ConversionStatus::Playable,
    ]);

    expect($movie->isPlayable())->toBeFalse();
});

test('a converted movie is unavailable while its files are being purged', function () {
    $movie = Movie::factory()->make([
        'download_status' => DownloadStatus::Purging,
        'conversion_status' => ConversionStatus::Converted,
    ]);

    expect($movie->isPlayable())->toBeFalse();
});
```

Ces tests sont non négociables : ils empêchent la purge de casser le streaming
progressif exigé par le sujet.

---

## Étape 3 — Créer la commande de purge

Générer la commande :

```bash
php artisan make:command PurgeStaleMovies \
    --command=movies:purge-stale \
    --no-interaction
```

Le projet utilise les attributs Laravel 13 `Signature` et `Description`. Une
implémentation complète de `app/Console/Commands/PurgeStaleMovies.php` peut
prendre cette forme :

```php
<?php

namespace App\Console\Commands;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Enums\ExternalSubtitleStatus;
use App\Models\Movie;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Signature('movies:purge-stale')]
#[Description('Delete movie files that have not been watched for one month')]
final class PurgeStaleMovies extends Command implements Isolatable
{
    private const BATCH_SIZE = 100;

    /**
     * Keep the isolation lock longer than any expected cleanup run.
     */
    public function isolationLockExpiresAt(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(): int
    {
        $cutoff = now()->subMonthNoOverflow();
        $purged = 0;
        $failed = 0;

        // Finish interrupted purges first. Their updated_at was changed when
        // they entered Purging, so their age must not be checked again.
        foreach ($this->interruptedPurges() as $movie) {
            $result = $this->purgeMovie($movie->id, $cutoff);
            $purged += $result === true ? 1 : 0;
            $failed += $result === false ? 1 : 0;
        }

        foreach ($this->newCandidates($cutoff) as $movie) {
            $result = $this->purgeMovie($movie->id, $cutoff);
            $purged += $result === true ? 1 : 0;
            $failed += $result === false ? 1 : 0;
        }

        $this->components->info("Purged {$purged} movie(s); {$failed} failure(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return iterable<int, Movie>
     */
    private function interruptedPurges(): iterable
    {
        return Movie::query()
            ->where('download_status', DownloadStatus::Purging->value)
            ->select('id')
            ->lazyById(self::BATCH_SIZE);
    }

    /**
     * @return iterable<int, Movie>
     */
    private function newCandidates(CarbonInterface $cutoff): iterable
    {
        return Movie::query()
            ->where('download_status', DownloadStatus::Completed->value)
            ->whereIn('conversion_status', [
                ConversionStatus::Pending->value,
                ConversionStatus::Failed->value,
                ConversionStatus::Converted->value,
            ])
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('last_watched_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('last_watched_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->select('id')
            ->lazyById(self::BATCH_SIZE);
    }

    /**
     * true: purged; false: failed; null: no longer eligible.
     */
    private function purgeMovie(int $movieId, CarbonInterface $cutoff): ?bool
    {
        try {
            $claimed = DB::transaction(function () use ($movieId, $cutoff): bool {
                $movie = Movie::query()
                    ->lockForUpdate()
                    ->find($movieId);

                if ($movie === null) {
                    return false;
                }

                if ($movie->download_status === DownloadStatus::Purging) {
                    return true;
                }

                if (! $this->isNewCandidate($movie, $cutoff)) {
                    return false;
                }

                $movie->download_status = DownloadStatus::Purging;
                $movie->save();

                return true;
            }, attempts: 3);

            if (! $claimed) {
                return null;
            }

            $disk = Storage::disk('public');
            $directory = "movies/{$movieId}";
            $deleted = ! $disk->directoryExists($directory)
                || $disk->deleteDirectory($directory);

            if (! $deleted) {
                throw new RuntimeException("Unable to delete {$directory}.");
            }

            $updated = Movie::query()
                ->whereKey($movieId)
                ->where('download_status', DownloadStatus::Purging->value)
                ->update([
                    'filename' => null,
                    'download_status' => DownloadStatus::Pending->value,
                    'download_id' => null,
                    'download_file_index' => null,
                    'download_error' => null,
                    'conversion_status' => ConversionStatus::Pending->value,
                    'conversion_attempt' => null,
                    'conversion_error' => null,
                    'conversion_started_at' => null,
                    'conversion_playable_at' => null,
                    'conversion_completed_at' => null,
                    'external_subtitles' => null,
                    'external_subtitles_status' => ExternalSubtitleStatus::None->value,
                    'last_watched_at' => null,
                ]);

            if ($updated !== 1) {
                throw new RuntimeException("Unable to reset movie {$movieId}.");
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('Movie purge failed.', [
                'movie_id' => $movieId,
                'exception' => $exception,
            ]);

            $this->components->error(
                "Movie {$movieId} could not be purged: {$exception->getMessage()}",
            );

            return false;
        }
    }

    private function isNewCandidate(Movie $movie, CarbonInterface $cutoff): bool
    {
        if ($movie->download_status !== DownloadStatus::Completed) {
            return false;
        }

        if (! in_array($movie->conversion_status, [
            ConversionStatus::Pending,
            ConversionStatus::Failed,
            ConversionStatus::Converted,
        ], true)) {
            return false;
        }

        $referenceDate = $movie->last_watched_at ?? $movie->updated_at;

        return $referenceDate !== null && $referenceDate->lessThanOrEqualTo($cutoff);
    }
}
```

### Pourquoi `lazyById()` ?

`lazyById()` exécute plusieurs requêtes par tranches d’identifiants, mais expose
les modèles un par un. Il évite de charger toute la bibliothèque en mémoire.
Contrairement à une pagination par offset, le parcours reste stable lorsque la
commande modifie `download_status` et `updated_at`.

Ne pas placer tout le parcours dans une transaction. La transaction et le verrou
doivent concerner un seul film et se terminer avant l’accès au disque.

### Pourquoi `Isolatable` ?

Avec le cache `database` déjà configuré par défaut, Laravel peut prendre un
verrou atomique global. La commande devra toujours être lancée avec
`--isolated` :

```bash
php artisan movies:purge-stale --isolated
```

Cela empêche un lancement cron et un lancement manuel de purger simultanément le
même film. L’expiration à six heures sert de filet de sécurité après un arrêt
brutal ; elle doit rester supérieure à la durée maximale réaliste de la commande.

### Pourquoi conserver `purging` après une erreur ?

Une suppression peut avoir retiré une partie du dossier avant d’échouer. Revenir
à `completed` ferait croire que ce média potentiellement incomplet est lisible.
Revenir à `pending` autoriserait un téléchargement dans un dossier encore en
cours de nettoyage. Conserver `purging` est donc l’unique état sûr.

---

## Étape 4 — Coordonner l’ouverture de la page

Le verrou de la commande ne suffit pas : un utilisateur peut ouvrir la page
entre la sélection initiale et la suppression. L’ouverture et la revendication
de la purge doivent verrouiller la même ligne PostgreSQL.

Dans `app/Http/Controllers/MovieController.php`, importer `DB`, puis recharger le
film dans une courte transaction au début de `show()` :

```php
use Illuminate\Support\Facades\DB;

public function show(Movie $movie): InertiaResponse
{
    $movie = DB::transaction(function () use ($movie): Movie {
        $lockedMovie = Movie::query()
            ->lockForUpdate()
            ->findOrFail($movie->id);

        if ($lockedMovie->download_status !== DownloadStatus::Purging) {
            WatchedMovie::markWatched(Auth::user(), $lockedMovie);
        }

        return $lockedMovie;
    }, attempts: 3);

    // Construire ensuite la réponse Inertia avec cette instance fraîche.
    // Le reste de la méthode actuelle demeure ici.
}
```

Les deux ordres possibles deviennent déterministes :

- la page verrouille la ligne en premier : elle actualise `last_watched_at`, puis
  la purge recharge la date récente et abandonne ;
- la purge verrouille la ligne en premier : elle enregistre `purging`, puis la
  page voit cet état et n’enregistre pas de visionnage.

Ne pas conserver le verrou pendant l’appel OMDb ou pendant le rendu Inertia. La
transaction doit uniquement recharger le film et enregistrer le visionnage.

---

## Étape 5 — Interdire une préparation pendant la purge

Une simple vérification effectuée sur l’ancien modèle issu du route model binding
ne suffit pas. La mise à jour qui réserve une conversion doit également exclure
`purging`, afin que PostgreSQL arbitre correctement une course avec la purge.

Dans `MovieConversionController::store()` :

1. retourner immédiatement si le modèle déjà chargé est `purging`, pour le cas
   courant et pour produire un log explicite ;
2. exclure `purging` de la branche qui démarre `DownloadMovie` ;
3. ajouter la même exclusion dans la requête atomique qui passe la conversion à
   `queued`.

Le principe est :

```php
if ($movie->download_status === DownloadStatus::Purging) {
    return to_route('movies.show', $movie);
}
```

Puis, dans la condition de démarrage du téléchargement :

```php
! in_array($movie->download_status, [
    DownloadStatus::Downloading,
    DownloadStatus::Completed,
    DownloadStatus::Purging,
], true)
```

Et dans la requête qui réserve la conversion :

```php
->whereNotIn('download_status', [
    DownloadStatus::Unsupported->value,
    DownloadStatus::Purging->value,
])
```

`DownloadMovie` accepte déjà uniquement `pending` et `failed` : il ne pourra donc
pas revendiquer un film `purging`. La purge, de son côté, revérifie sous verrou
que la conversion n’est pas devenue `queued`, `converting` ou `playable`.

Dans les props de `MovieController::show()`, calculer `can_prepare` ainsi :

```php
'can_prepare' => ! in_array($movie->download_status, [
    DownloadStatus::Unsupported,
    DownloadStatus::Purging,
], true) && in_array($movie->conversion_status, [
    ConversionStatus::Pending,
    ConversionStatus::Failed,
], true),
```

---

## Étape 6 — Afficher l’état dans React

Dans `resources/js/pages/movies/show.tsx`, ajouter la valeur au type :

```ts
type DownloadStatus =
    | 'pending'
    | 'downloading'
    | 'completed'
    | 'failed'
    | 'unsupported'
    | 'purging';
```

Utiliser la décision calculée par le serveur plutôt que de recalculer la
disponibilité uniquement depuis `conversion_status` :

```ts
const isPurging = moviePageData.download_status === 'purging';
const videoIsPlayable = moviePageData.playable;
```

Cette seconde ligne est essentielle : un film peut conserver
`conversion_status = converted` pendant que `download_status = purging`.

Pendant `purging` :

- ne pas monter `HlsPlayer` ;
- ne pas afficher le bouton `Prepare video` ;
- afficher un message en anglais, par exemple :

```text
This video is currently being cleaned up.
Please try again shortly.
```

Le polling Inertia existant peut continuer pendant cet état. Une fois la purge
terminée, la page recevra `download_status = pending` et pourra de nouveau
proposer la préparation.

---

## Étape 7 — Tester la commande

Créer un test de fonctionnalité :

```bash
php artisan make:test --pest PurgeStaleMoviesTest --no-interaction
```

Utiliser `Storage::fake('public')` et une date figée. Les scénarios minimaux sont :

1. un film `completed/converted` visionné il y a plus d’un mois est supprimé ;
2. un film dont `last_watched_at` est récent est conservé, même si son
   `updated_at` est ancien ;
3. quand `last_watched_at` est nul, `updated_at` sert de repli ;
4. `downloading`, `queued`, `converting` et `playable` ne sont jamais purgés ;
5. un dossier absent provoque quand même la réinitialisation de la ligne ;
6. un film déjà `purging` est repris ;
7. après succès, tous les champs temporaires sont réinitialisés ;
8. après échec du disque, le statut reste `purging` ;
9. une préparation `purging` ne publie ni `DownloadMovie` ni `ConvertMovie` ;
10. la page d’un film `purging` expose `playable = false` et
    `can_prepare = false` sans enregistrer de nouveau visionnage.

Exemple du cas principal :

```php
use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Models\Movie;
use Illuminate\Support\Facades\Storage;

test('it purges a downloaded movie not watched for one month', function () {
    Storage::fake('public');

    $movie = Movie::factory()->create([
        'download_status' => DownloadStatus::Completed,
        'conversion_status' => ConversionStatus::Converted,
        'last_watched_at' => now()->subMonthNoOverflow()->subDay(),
        'filename' => 'movie.mp4',
    ]);

    Storage::disk('public')->put("movies/{$movie->id}/movie.mp4", 'video');
    Storage::disk('public')->put("movies/{$movie->id}/hls/attempt/index.m3u8", 'playlist');

    $this->artisan('movies:purge-stale --isolated')->assertSuccessful();

    expect(Storage::disk('public')->directoryExists("movies/{$movie->id}"))->toBeFalse();

    $movie->refresh();

    expect($movie->download_status)->toBe(DownloadStatus::Pending)
        ->and($movie->conversion_status)->toBe(ConversionStatus::Pending)
        ->and($movie->filename)->toBeNull()
        ->and($movie->last_watched_at)->toBeNull();
});
```

Pour tester un échec de suppression, simuler un disque dont
`deleteDirectory()` retourne `false`, ou extraire l’accès au stockage derrière
un petit service injectable. Ne pas provoquer l’erreur avec des permissions
réelles dans la suite de tests.

Les tests SQLite couvrent les règles fonctionnelles, mais `lockForUpdate()` y est
effectivement sans effet. Le scénario de concurrence doit aussi être vérifié
contre PostgreSQL, le moteur utilisé par `docker-compose.yml`.

---

## Étape 8 — Ajouter le cron autonome à Docker Compose

Le besoin porte sur une seule exécution quotidienne. Nous n’utilisons donc pas
`schedule:work`, qui conserverait Laravel en mémoire et lancerait
`schedule:run` chaque minute.

Le service `scheduler` utilise le même environnement PHP que `app`, mais son
processus permanent est uniquement le démon cron. PHP ne démarre qu’une fois par
jour pour exécuter la commande.

### Installer cron dans l’image

Dans le `RUN apt-get install` du `Dockerfile`, ajouter :

```dockerfile
cron \
```

Le service `app` continue de fonctionner sous l’utilisateur `webapp`. Seul le
service `scheduler` démarrera le démon en root ; la ligne cron exécutera Artisan
en tant que `webapp`.

### Créer `docker/scheduler-entrypoint.sh`

```bash
#!/usr/bin/env bash

set -euo pipefail

cd /var/www/html

cleanup_time="${MOVIE_CLEANUP_TIME:-03:00}"

if [[ ! "${cleanup_time}" =~ ^([01][0-9]|2[0-3]):([0-5][0-9])$ ]]; then
    echo "MOVIE_CLEANUP_TIME must use the HH:MM format." >&2
    exit 1
fi

hour="${BASH_REMATCH[1]}"
minute="${BASH_REMATCH[2]}"

{
    echo 'SHELL=/bin/bash'
    echo 'HOME=/home/webapp'
    echo 'PATH=/usr/local/bin:/usr/bin:/bin'
    echo "${minute} ${hour} * * * webapp cd /var/www/html && /usr/local/bin/php artisan movies:purge-stale --isolated --no-interaction >> /proc/1/fd/1 2>> /proc/1/fd/2"
} > /etc/cron.d/hypertube

chmod 0644 /etc/cron.d/hypertube

echo "Movie cleanup scheduled every day at ${cleanup_time} (container time)."

exec /usr/sbin/cron -f
```

Rendre le script exécutable :

```bash
chmod +x docker/scheduler-entrypoint.sh
```

Le format est validé avant d’écrire la crontab : une valeur provenant de
l’environnement ne doit jamais être injectée sans contrôle dans une commande
shell.

L’heure est exprimée dans le fuseau du conteneur, UTC par défaut. Si un fuseau
local devient nécessaire, le configurer explicitement et tester les changements
d’heure ; ne pas supposer que le fuseau Laravel modifie celui de cron.

### Signaler que `app` est prêt

Le conteneur `app` installe actuellement Composer, prépare `.env` et exécute les
migrations dans `docker/entrypoint.sh`. Le scheduler ne doit pas démarrer Artisan
avant la fin de cette préparation.

Juste avant `exec php-fpm -F`, ajouter :

```bash
touch /tmp/hypertube-app-ready
```

Puis ajouter un healthcheck au service `app` :

```yaml
healthcheck:
  test: ["CMD", "test", "-f", "/tmp/hypertube-app-ready"]
  interval: 2s
  timeout: 2s
  retries: 60
```

Le fichier vit dans le conteneur `app` et disparaît avec lui. Il indique que
l’entrypoint a terminé les dépendances et les migrations, pas seulement que le
processus Docker a été créé.

### Ajouter le service `scheduler`

Dans `docker-compose.yml`, réutiliser la même construction que `app` :

```yaml
scheduler:
  build:
    context: .
    dockerfile: Dockerfile
    args:
      WWW_USER: "${HOST_UID:-1000}"
      WWW_GROUP: "${HOST_GID:-1000}"
  user: root
  volumes:
    - .:/var/www/html
  environment:
    MOVIE_CLEANUP_TIME: "${MOVIE_CLEANUP_TIME:-03:00}"
  entrypoint: ./docker/scheduler-entrypoint.sh
  restart: unless-stopped
  depends_on:
    app:
      condition: service_healthy
    postgres:
      condition: service_healthy
```

Les couches Docker issues du même `Dockerfile` sont partagées ; le second service
ne duplique pas physiquement toute l’image. Au repos, il conserve seulement cron,
pas une application Laravel en mémoire.

Ajouter dans `.env.example` :

```dotenv
MOVIE_CLEANUP_TIME=03:00
```

Il ne faut rien ajouter à `routes/console.php` pour la planification : dans cette
architecture, cron appelle directement `movies:purge-stale`. Le fichier de route
console reste responsable de la découverte des commandes, pas de leur heure
d’exécution.

---

## Étape 9 — Vérifier manuellement

Reconstruire l’image, car le paquet cron a été ajouté :

```bash
docker compose up -d --build
```

Vérifier que tous les services sont actifs :

```bash
docker compose ps
docker compose logs scheduler
```

Le log doit indiquer l’heure quotidienne configurée.

Tester la commande sans attendre cron :

```bash
docker compose exec --user webapp scheduler \
    php artisan movies:purge-stale --isolated
```

Vérifier les tests ciblés :

```bash
php artisan test --compact tests/Feature/Movies/MoviePlayabilityTest.php
php artisan test --compact tests/Feature/Movies/PurgeStaleMoviesTest.php
php artisan test --compact tests/Feature/Movies/MovieControllerTest.php
php artisan test --compact tests/Feature/Movies/MovieConversionControllerTest.php
```

Puis vérifier le frontend et le formatage :

```bash
npm run types:check
npm run lint:check
vendor/bin/pint --dirty --format agent
```

## Checklist finale

- [ ] `downloading + playable` reste lisible.
- [ ] `downloading + converted` reste lisible.
- [ ] `purging` bloque le lecteur et la préparation.
- [ ] La purge ne sélectionne jamais `queued`, `converting` ou `playable`.
- [ ] La ligne est revérifiée sous `lockForUpdate()` avant suppression.
- [ ] Le verrou PostgreSQL est libéré avant la suppression du dossier.
- [ ] Une ouverture récente de la page annule la candidature à la purge.
- [ ] Un dossier absent est traité comme déjà supprimé.
- [ ] Une erreur disque laisse le film en `purging`.
- [ ] Une exécution suivante reprend les films `purging`.
- [ ] `filename` et `external_subtitles` sont vidés après succès.
- [ ] `external_subtitles_status` revient à `none`.
- [ ] La commande est lancée avec `--isolated`.
- [ ] Le scheduler attend que `app` soit healthy.
- [ ] Le cron démarre automatiquement avec `docker compose up`.
- [ ] Les textes visibles dans l’interface sont en anglais.

Une fois ces points couverts, le projet respecte la règle de conservation du
sujet sans compromettre son autre exigence essentielle : commencer à convertir
et à diffuser la vidéo pendant que le torrent est encore en cours de
téléchargement.
