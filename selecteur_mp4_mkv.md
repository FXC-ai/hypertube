# Sélectionner soi-même le MP4 ou le MKV d’un torrent

## Objectif

Cette modification fait de Laravel l’autorité qui choisit la source vidéo d’un torrent.
Le client torrent continue uniquement à inspecter le torrent et à télécharger les index
que Laravel lui transmet.

Après cette modification :

- `mainVideoIndex`, `kind`, `container` et `suggested` ne participent plus au choix de la vidéo ;
- seuls les fichiers dont le chemin se termine par `.mp4` ou `.mkv` sont éligibles ;
- la comparaison des extensions ne tient pas compte de la casse ;
- un fichier vide est rejeté ;
- parmi plusieurs candidats, le plus gros est choisi, puis le plus petit index en cas d’égalité ;
- seul le fichier vidéo choisi est téléchargé ; les `.srt` externes ne le sont plus ;
- si aucun candidat n’existe, le téléchargement devient définitivement `unsupported` ;
- aucune conversion et aucune surveillance du téléchargement ne sont lancées dans ce cas ;
- le backend refuse également une nouvelle demande de préparation ;
- le frontend masque le bouton et explique pourquoi la vidéo n’est pas disponible.

> Important : cette vérification valide le nom et l’extension annoncés dans le torrent,
> pas les octets du fichier. Un fichier appelé `movie.mp4` peut encore contenir autre chose.
> Une validation ultérieure avec `detectedContainer` ou `ffprobe` serait un second garde-fou.

## Pourquoi ne pas ajouter `movies.valid`

Un booléen ne peut pas distinguer « pas encore inspecté » de « inspecté et non supporté ».
Il dupliquerait aussi une information déjà portée par le cycle de téléchargement. On ajoute
donc un état terminal à `DownloadStatus` :

```text
pending -> downloading -> completed
                    \----> failed       (erreur technique, retentable)
                    \----> unsupported  (aucun MP4/MKV, terminal)
```

On ne modifie pas `ConversionStatus`. Une conversion sans source n’a pas échoué : elle n’a
jamais commencé. Cette séparation prépare aussi la future évolution où les MKV seront
convertis, alors que les MP4 seront servis directement.

La colonne `download_status` est déjà une chaîne sans contrainte SQL. Aucune migration n’est
donc nécessaire pour ajouter la nouvelle valeur à l’enum PHP.

## 1. Créer le sélecteur

Créer app/Services/Torrent/TorrentVideoSelector.php avec le contenu entier suivant :
```php
<?php

namespace App\Services\Torrent;

final class TorrentVideoSelector
{
    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = ['mp4', 'mkv'];

    /**
     * @param  list<array{index: int, path: string, fileName: string, length: int, kind: string, suggested: bool}>  $files
     * @return array{index: int, path: string, fileName: string, length: int, kind: string, suggested: bool}|null
     */
    public function select(array $files): ?array
    {
        $candidates = array_values(array_filter(
            $files,
            fn (array $file): bool => $file['length'] > 0
                && in_array($this->extension($file['path']), self::SUPPORTED_EXTENSIONS, true),
        ));

        usort(
            $candidates,
            fn (array $left, array $right): int => (
                $this->extensionPriority($left['path'])
                <=> $this->extensionPriority($right['path'])
            )
                ?: ($right['length'] <=> $left['length'])
                ?: ($left['index'] <=> $right['index']),
        );

        return $candidates[0] ?? null;
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function extensionPriority(string $path): int
    {
        return match ($this->extension($path)) {
            'mp4' => 0,
            'mkv' => 1,
            default => PHP_INT_MAX,
        };
    }
}
```

Le sélecteur regarde path, qui représente le nom original dans le torrent. Il continue cependant à retourner fileName, car le contrat du client torrent indique que c’est le nom aplati réellement écrit dans storage/app/public/movies/{id}.

Le tri applique trois critères successifs :

1. Les fichiers MP4 sont placés avant les fichiers MKV.
2. Parmi les fichiers ayant la même extension, le plus gros est placé en premier.
3. Si deux fichiers du même format ont exactement la même taille, celui ayant le plus petit index est placé en premier.

La méthode extensionPriority() attribue la priorité 0 aux MP4 et 1 aux MKV. Comme usort() place la valeur la plus faible en premier, un MP4 sera toujours sélectionné lorsqu’au moins un MP4 non vide est disponible, même si un MKV est plus gros.

La priorité PHP_INT_MAX constitue une valeur de repli défensive. Elle ne devrait normalement jamais être utilisée, car les extensions non supportées ont déjà été retirées par le filtre.

La politique de sélection devient donc :

MP4 disponible
    -> choisir le plus gros MP4
    -> en cas d’égalité, choisir celui avec le plus petit index

Aucun MP4, mais au moins un MKV
    -> choisir le plus gros MKV
    -> en cas d’égalité, choisir celui avec le plus petit index

Aucun MP4 ni MKV
    -> retourner null

## 2. Ajouter le statut terminal

Remplacer entièrement `app/Enums/DownloadStatus.php` par :

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
}
```

`Failed` reste réservé aux pannes techniques susceptibles de réussir lors d’une nouvelle
tentative. `Unsupported` exprime une décision métier déterministe pour ce torrent.

## 3. Modifier le job de téléchargement

Remplacer entièrement `app/Jobs/DownloadMovie.php` par :

```php
<?php

namespace App\Jobs;

use App\Enums\ConversionStatus;
use App\Enums\DownloadStatus;
use App\Models\Movie;
use App\Services\Torrent\TorrentClient;
use App\Services\Torrent\TorrentVideoSelector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Starts the torrent download of a movie, then the conversion right away: while the file
 * downloads, ConvertMovie reads it through the Client Torrent streaming URL (ADR-0007).
 *
 * Local test scaffolding: the real download pipeline (retries, Exhausted...) belongs to #18.
 */
final class DownloadMovie implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $movieId)
    {
        $this->onConnection('monitoring');
        $this->onQueue('monitoring');
    }

    public function handle(TorrentClient $torrentClient, TorrentVideoSelector $videoSelector): void
    {
        $context = [
            'class' => self::class,
            'method' => __FUNCTION__,
            'movie_id' => $this->movieId,
        ];

        Log::channel('my_debug')->info(
            "Le job de téléchargement du film démarre.\n".json_encode(
                [...$context],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            )."\n",
        );

        $claimed = Movie::query()
            ->whereKey($this->movieId)
            ->whereNotNull('torrent_url')
            ->whereIn('download_status', [DownloadStatus::Pending->value, DownloadStatus::Failed->value])
            ->update([
                'download_status' => DownloadStatus::Downloading->value,
                'download_id' => null,
                'download_file_index' => null,
                'download_error' => null,
            ]);

        if ($claimed !== 1) {
            $movie = Movie::query()->find($this->movieId);

            $reason = match (true) {
                $movie === null => 'movie_not_found',
                $movie->torrent_url === null => 'torrent_url_missing',
                $movie->download_status === DownloadStatus::Unsupported => 'torrent_unsupported',
                default => 'download_status_not_eligible',
            };

            Log::channel('my_debug')->debug(
                "Le téléchargement du film ne peut pas démarrer.\n".json_encode(
                    [
                        ...$context,
                        'reason' => $reason,
                        'download_status' => $movie?->download_status?->value,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            return;
        }

        $movie = Movie::query()->findOrFail($this->movieId);

        try {
            $inspection = $torrentClient->inspect($movie->torrent_url);
            $selectedVideo = $videoSelector->select($inspection['files']);

            Log::channel('my_debug')->info(
                "L’inspection du torrent est terminée.\n".json_encode(
                    [
                        ...$context,
                        'file_count' => count($inspection['files']),
                        'selected_video' => $selectedVideo,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            if ($selectedVideo === null) {
                $reason = 'The torrent contains no supported MP4 or MKV video file.';

                Movie::query()
                    ->whereKey($movie->id)
                    ->where('download_status', DownloadStatus::Downloading->value)
                    ->update([
                        'filename' => null,
                        'download_status' => DownloadStatus::Unsupported->value,
                        'download_id' => null,
                        'download_file_index' => null,
                        'download_error' => $reason,
                    ]);

                Log::channel('my_debug')->warning(
                    "Le torrent ne contient aucune vidéo MP4 ou MKV prise en charge.\n".json_encode(
                        [...$context, 'reason' => 'supported_video_missing'],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                    )."\n",
                );

                return;
            }

            $downloadId = $torrentClient->startDownload(
                $movie->torrent_url,
                Storage::disk('public')->path("movies/{$movie->id}"),
                [$selectedVideo['index']],
                $inspection['infoHash'],
            );

            Log::channel('my_debug')->info(
                "Le client torrent a accepté la demande de téléchargement.\n".json_encode(
                    [...$context, 'download_id' => $downloadId],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            Movie::query()->whereKey($movie->id)->update([
                'download_id' => $downloadId,
                'download_file_index' => $selectedVideo['index'],
                'filename' => $selectedVideo['fileName'],
            ]);

            Log::channel('my_debug')->info(
                "Les informations du téléchargement ont été enregistrées pour le film.\n".json_encode(
                    [
                        ...$context,
                        'download_id' => $downloadId,
                        'file_index' => $selectedVideo['index'],
                        'filename' => $selectedVideo['fileName'],
                        'selected_file_count' => 1,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );
        } catch (Throwable $exception) {
            Movie::query()->whereKey($movie->id)->update([
                'download_status' => DownloadStatus::Failed->value,
                'download_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            Log::channel('my_debug')->error(
                "Le téléchargement du film a échoué.\n".json_encode(
                    [
                        ...$context,
                        'exception' => [
                            'type' => $exception::class,
                            'message' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );

            throw $exception;
        }

        $queued = Movie::query()
            ->whereKey($movie->id)
            ->where('download_status', DownloadStatus::Downloading->value)
            ->whereIn('conversion_status', [ConversionStatus::Pending->value, ConversionStatus::Failed->value])
            ->update([
                'conversion_status' => ConversionStatus::Queued->value,
                'conversion_attempt' => null,
                'conversion_error' => null,
                'conversion_started_at' => null,
                'conversion_playable_at' => null,
                'conversion_completed_at' => null,
            ]);

        if ($queued === 1) {
            ConvertMovie::dispatch($movie->id);

            Log::channel('my_debug')->info(
                "Le job de conversion du film a été ajouté à la file d’attente.\n".json_encode(
                    [
                        ...$context,
                        'download_id' => $downloadId,
                        'conversion_status' => ConversionStatus::Queued->value,
                        'job' => ConvertMovie::class,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );
        } else {
            $movie->refresh();

            Log::channel('my_debug')->debug(
                "La conversion du film n’a pas été ajoutée à la file d’attente car son statut ne le permet pas.\n".json_encode(
                    [
                        ...$context,
                        'download_id' => $downloadId,
                        'conversion_status' => $movie->conversion_status?->value,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )."\n",
            );
        }

        MonitorDownload::dispatch($movie->id)->delay(now()->addSeconds(MonitorDownload::POLL_SECONDS));

        Log::channel('my_debug')->info(
            "Le suivi du téléchargement a été programmé.\n".json_encode(
                [
                    ...$context,
                    'download_id' => $downloadId,
                    'delay_seconds' => MonitorDownload::POLL_SECONDS,
                    'job' => MonitorDownload::class,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            )."\n",
        );
    }
}
```

Deux détails sont importants :

1. l’absence de vidéo fait un `return`, elle ne lance pas une exception qui serait capturée
   comme une panne technique ;
2. la mise en file de la conversion exige encore `download_status = downloading`. Cela
   empêche une transition concurrente vers `unsupported` de lancer une conversion.

## 4. Bloquer une nouvelle préparation côté backend

Dans `app/Http/Controllers/MovieConversionController.php`, ajouter le garde suivant juste
avant le `if` qui décide de lancer `DownloadMovie` :

```php
if ($movie->download_status === DownloadStatus::Unsupported) {
    Log::channel('my_debug')->debug(
        "La préparation du film est refusée car son torrent n’est pas pris en charge.\n".json_encode(
            [...$context, 'reason' => 'torrent_unsupported'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        )."\n",
    );

    return to_route('movies.show', $movie);
}
```

Puis renforcer la requête qui met la conversion en file :

```php
$queued = Movie::query()
    ->whereKey($movie->id)
    ->where('download_status', '!=', DownloadStatus::Unsupported->value)
    ->whereIn('conversion_status', [ConversionStatus::Pending->value, ConversionStatus::Failed->value])
    ->update([
        'conversion_status' => ConversionStatus::Queued->value,
        'conversion_attempt' => null,
        'conversion_error' => null,
        'conversion_started_at' => null,
        'conversion_playable_at' => null,
        'conversion_completed_at' => null,
    ]);
```

Le premier contrôle répond immédiatement à une requête normale. La condition SQL protège
également contre un changement de statut entre la lecture du modèle et son `update`.

Le job `DownloadMovie` possède déjà son propre verrou atomique : il ne réclame que les états
`pending` ou `failed`. Un job obsolète associé à un film `unsupported` s’arrêtera donc sans
contacter le client torrent.

## 5. Envoyer l’état au frontend

Dans le tableau `moviePageData` de `app/Http/Controllers/MovieController.php`, remplacer les
propriétés de statut par le bloc suivant :

```php
'download_status' => $movie->download_status->value,
'download_error' => $movie->download_status === DownloadStatus::Unsupported
    ? $movie->download_error
    : null,
'conversion_attempt' => $movie->conversion_attempt,
'conversion_status' => $movie->conversion_status->value,
'conversion_error' => $movie->conversion_status === ConversionStatus::Failed
    ? $movie->conversion_error
    : null,
'can_prepare' => $movie->download_status !== DownloadStatus::Unsupported
    && in_array($movie->conversion_status, [ConversionStatus::Pending, ConversionStatus::Failed], true),
'playable' => $movie->isPlayable(),
```

Ajouter aussi cet import en tête du contrôleur :

```php
use App\Enums\DownloadStatus;
```

`can_prepare` est calculé par le backend : React n’a pas à reconstruire les règles métier.

## 6. Adapter la page React

Dans `resources/js/pages/movies/show.tsx`, ajouter le type :

```tsx
type DownloadStatus =
    | 'pending'
    | 'downloading'
    | 'completed'
    | 'failed'
    | 'unsupported';
```

Compléter `MoviePageData` :

```tsx
type MoviePageData = {
    id: number;
    title: string;
    filename: string | null;
    download_status: DownloadStatus;
    download_error: string | null;
    conversion_attempt: string | null;
    conversion_status: ConversionStatus;
    conversion_error: string | null;
    can_prepare: boolean;
    playable: boolean;
    preferredlanguage: string;
    movieData: OmdbData;
};
```

Dans le composant, calculer l’état terminal :

```tsx
const isUnsupported = moviePageData.download_status === 'unsupported';
const statusLabel = isUnsupported
    ? 'Unsupported torrent'
    : conversionStatusLabel[moviePageData.conversion_status];
const displayedError = isUnsupported
    ? moviePageData.download_error
    : moviePageData.conversion_error;
```

Arrêter aussi le polling sur cet état :

```tsx
useEffect(() => {
    if (
        isUnsupported ||
        moviePageData.conversion_status === 'playable' ||
        moviePageData.conversion_status === 'failed'
    ) {
        stop();
    }
}, [isUnsupported, moviePageData.conversion_status, stop]);
```

Dans l’état vide du lecteur, remplacer les deux textes par :

```tsx
<p className="font-medium text-foreground">
    {isUnsupported
        ? 'This torrent has no supported video'
        : isPreparing
          ? 'The video is being prepared'
          : 'The video is not available yet'}
</p>
<p className="mt-1 text-sm">
    {isUnsupported
        ? 'No MP4 or MKV file was found.'
        : isPreparing
          ? 'This page will update automatically.'
          : 'Prepare the video to start watching.'}
</p>
```

Remplacer l’affichage de l’erreur par :

```tsx
{displayedError && (
    <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
        {displayedError}
    </p>
)}
```

Enfin, ajouter `moviePageData.can_prepare &&` devant la condition actuelle du bouton :

```tsx
{moviePageData.can_prepare &&
    (moviePageData.conversion_status === 'pending' ||
        moviePageData.conversion_status === 'failed') && (
        <Button
            type="button"
            className="w-full"
            disabled={conversionForm.processing}
            onClick={startConversion}
        >
            {moviePageData.conversion_status === 'failed' ? (
                <RotateCcw />
            ) : (
                <Play />
            )}
            {moviePageData.conversion_status === 'failed'
                ? 'Retry'
                : 'Prepare video'}
        </Button>
    )}
```

Le masquage du bouton améliore l’interface, mais le contrôleur reste l’autorité. Une requête
HTTP forgée manuellement ne pourra pas contourner la règle.

## 7. Tests du sélecteur

Créer `tests/Unit/TorrentVideoSelectorTest.php` avec le contenu entier suivant :

```php
<?php

use App\Services\Torrent\TorrentVideoSelector;

function torrentFile(int $index, string $path, int $length): array
{
    return [
        'index' => $index,
        'path' => $path,
        'fileName' => basename($path),
        'length' => $length,
        'kind' => 'untrusted',
        'suggested' => false,
    ];
}

test('it selects the largest MP4 or MKV without trusting client classifications', function () {
    $selected = app(TorrentVideoSelector::class)->select([
        torrentFile(0, 'movie.srt', 2_000_000_000),
        torrentFile(1, 'bonus.avi', 1_900_000_000),
        torrentFile(2, 'movie.MP4', 800_000_000),
        torrentFile(3, 'movie.mkv', 1_000_000_000),
    ]);

    expect($selected)
        ->not->toBeNull()
        ->and($selected['index'])->toBe(3);
});

test('it rejects empty files and torrents without MP4 or MKV files', function () {
    $selected = app(TorrentVideoSelector::class)->select([
        torrentFile(0, 'empty.mp4', 0),
        torrentFile(1, 'movie.webm', 1_000_000_000),
        torrentFile(2, 'movie.srt', 50_000),
    ]);

    expect($selected)->toBeNull();
});

test('it uses the lowest torrent index when candidates have the same size', function () {
    $selected = app(TorrentVideoSelector::class)->select([
        torrentFile(8, 'second.mkv', 1_000),
        torrentFile(3, 'first.mp4', 1_000),
    ]);

    expect($selected['index'])->toBe(3);
});
```

## 8. Tests d’intégration à ajouter

Dans `tests/Feature/Movies/DownloadMovieTest.php`, rendre la fausse inspection configurable,
puis ajouter les deux scénarios suivants. Le premier prouve que Laravel ignore réellement
`mainVideoIndex`; le second prouve l’arrêt propre du pipeline.

```php
test('DownloadMovie ignores the client main video and downloads only the selected MP4 or MKV', function () {
    Queue::fake();
    Storage::fake('public');

    Http::fake([
        '*/torrents/inspect' => Http::response([
            'infoHash' => INFO_HASH,
            'mainVideoIndex' => 0,
            'files' => [
                ['index' => 0, 'path' => 'wrong.srt', 'fileName' => 'wrong.srt', 'length' => 2_000, 'kind' => 'video', 'suggested' => true],
                ['index' => 1, 'path' => 'small.mp4', 'fileName' => 'small.mp4', 'length' => 800, 'kind' => 'other', 'suggested' => false],
                ['index' => 2, 'path' => 'movie.MKV', 'fileName' => 'movie.MKV', 'length' => 1_000, 'kind' => 'other', 'suggested' => false],
            ],
        ]),
        '*/downloads' => Http::response(['id' => 'job-1'], 202),
    ]);

    $movie = torrentMovie();

    app()->call([new DownloadMovie($movie->id), 'handle']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/downloads')
        && $request['fileIndexes'] === [2]);

    $movie->refresh();

    expect($movie->download_file_index)->toBe(2)
        ->and($movie->filename)->toBe('movie.MKV');
});

test('a torrent without MP4 or MKV becomes unsupported and starts no pipeline jobs', function () {
    Queue::fake();
    Storage::fake('public');

    Http::fake([
        '*/torrents/inspect' => Http::response([
            'infoHash' => INFO_HASH,
            'mainVideoIndex' => 1,
            'files' => [
                ['index' => 0, 'path' => 'movie.srt', 'fileName' => 'movie.srt', 'length' => 500, 'kind' => 'subtitle', 'suggested' => true],
                ['index' => 1, 'path' => 'movie.avi', 'fileName' => 'movie.avi', 'length' => 1_000, 'kind' => 'video', 'suggested' => true],
            ],
        ]),
    ]);

    $movie = torrentMovie();

    app()->call([new DownloadMovie($movie->id), 'handle']);

    $movie->refresh();

    expect($movie->download_status)->toBe(DownloadStatus::Unsupported)
        ->and($movie->download_error)->toBe('The torrent contains no supported MP4 or MKV video file.')
        ->and($movie->download_id)->toBeNull()
        ->and($movie->download_file_index)->toBeNull()
        ->and($movie->filename)->toBeNull()
        ->and($movie->conversion_status)->toBe(ConversionStatus::Pending);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/downloads'));
    Queue::assertNotPushed(ConvertMovie::class);
    Queue::assertNotPushed(MonitorDownload::class);
});
```

Le test existant « downloads the suggested files » doit également être renommé et son
assertion `[1, 2]` remplacée par `[2]`, car les suggestions du client ne sont plus utilisées.

Dans `tests/Feature/Movies/MovieConversionControllerTest.php`, ajouter les imports
`DownloadStatus` et `DownloadMovie`, puis ce test :

```php
test('an unsupported torrent cannot be prepared again', function () {
    Queue::fake();

    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Pending)
        ->create([
            'torrent_url' => TORRENT_URL,
            'download_status' => DownloadStatus::Unsupported,
            'download_error' => 'The torrent contains no supported MP4 or MKV video file.',
        ]);

    app(MovieConversionController::class)->store($movie);

    expect($movie->refresh()->conversion_status)->toBe(ConversionStatus::Pending);
    Queue::assertNotPushed(DownloadMovie::class);
    Queue::assertNotPushed(ConvertMovie::class);
});
```

Si la constante `TORRENT_URL` n’est pas partagée entre fichiers de test, utiliser directement
une URL factice dans ce test plutôt que de redéclarer une constante globale portant le même nom.

## 9. Commandes de création et de vérification

Laravel demande de créer les classes et tests avec Artisan avant de remplacer leur contenu :

```bash
php artisan make:class Services/Torrent/TorrentVideoSelector --no-interaction
php artisan make:test --pest --unit TorrentVideoSelectorTest --no-interaction
```

Après avoir appliqué toutes les modifications :

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/TorrentVideoSelectorTest.php
php artisan test --compact tests/Feature/Movies/DownloadMovieTest.php
php artisan test --compact tests/Feature/Movies/MovieConversionControllerTest.php
npm run types:check
```

## 10. Points à vérifier manuellement

1. Un torrent avec MP4 et SRT doit envoyer uniquement l’index du MP4 à `/downloads`.
2. Un torrent avec un AVI plus gros et un MKV plus petit doit choisir le MKV.
3. Un torrent sans MP4/MKV doit afficher le message terminal en anglais et aucun bouton.
4. Recharger la page ou forger un POST vers `movies.conversion.store` ne doit lancer aucun job.
5. Une panne réseau du client torrent doit rester `failed`, donc retentable.

## 11. Préparation de l’évolution MP4 direct / MKV converti

Cette modification doit être faite avant le streaming direct des MP4. Elle établit une source
unique et fiable, commune aux deux futurs chemins. Dans l’issue suivante, la branche pourra
être choisie à partir de l’extension du `filename` sélectionné :

```text
TorrentVideoSelector
        |
        +-- MP4 -> téléchargement -> fichier servi par nginx
        |
        +-- MKV -> téléchargement/stream partiel -> conversion HLS
```

À ce moment-là, il faudra éviter de présenter `conversion_status` comme l’état global de
préparation : pour un MP4 direct, aucune conversion n’est nécessaire. Un état de lecture
agrégé calculé par le backend (`pending`, `preparing`, `playable`, `unsupported`) sera alors
plus adapté à l’interface.
