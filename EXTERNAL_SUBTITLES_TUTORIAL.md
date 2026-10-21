# Ajouter les sous-titres externes sans modifier le pipeline HLS

Ce tutoriel décrit une architecture simple pour télécharger les fichiers `.srt` et `.vtt` présents dans le torrent, puis les rendre sélectionnables dans le lecteur.

Elle répond aux exigences suivantes :

- `docs/42/subject.md`, section **III.3 Video Part** : les sous-titres anglais disponibles doivent être téléchargés et proposés dans le lecteur ; les sous-titres disponibles doivent également être proposés lorsque la langue de la vidéo ne correspond pas à la langue préférée de l'utilisateur ;
- `docs/42/evaluation.md`, section **Subtitles** : ces sous-titres doivent être téléchargés automatiquement et être directement sélectionnables.

Dans cette implémentation, tous les sous-titres externes trouvés dans le torrent sont téléchargés. Cela couvre donc automatiquement les deux critères lorsqu'un fichier correspondant est disponible.

## 1. Architecture retenue

Le pipeline devient le suivant :

1. `MovieConversionController` lance seulement le pipeline existant.
2. `DownloadMovie` inspecte le torrent, sélectionne la vidéo et tous les `.srt`/`.vtt`, puis demande leur téléchargement ensemble.
3. `ConvertMovie` continue de convertir uniquement la vidéo et les pistes incorporées au conteneur.
4. `MonitorDownload` surveille le téléchargement complet du torrent.
5. Le seul `MonitorDownload` qui réussit la transition atomique `downloading -> completed` place les sous-titres externes dans l'état `queued` et déclenche `PrepareExternalSubtitles`.
6. `PrepareExternalSubtitles` convertit chaque fichier externe en WebVTT sur une queue séparée.
7. Le frontend continue son polling jusqu'à ce que les sous-titres atteignent un état terminal, puis ajoute les fichiers prêts avec des balises HTML `<track>`.

`MonitorDownload` ne convertit donc aucun fichier. Il constate seulement que la condition nécessaire est satisfaite : le téléchargement complet est terminé.

Les sous-titres externes ne sont pas ajoutés au manifeste HLS. Cette feature n'a pas besoin de segmenter les sous-titres : un fichier WebVTT complet fonctionne directement avec `<track>`. Cela évite de relancer FFmpeg sur la vidéo, de réécrire le manifeste HLS ou de redémarrer Hls.js pendant la lecture.

## 2. Les états des sous-titres externes

Créer `app/Enums/ExternalSubtitleStatus.php` :

```php
<?php

namespace App\Enums;

enum ExternalSubtitleStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
```

Signification des états :

| État | Signification |
|---|---|
| `none` | Aucun sous-titre externe n'a été trouvé. |
| `pending` | Les fichiers ont été sélectionnés et sont encore en téléchargement. |
| `queued` | Le téléchargement est terminé et le job de préparation a été demandé. |
| `processing` | Le job prépare actuellement les WebVTT. |
| `ready` | Le traitement est terminé ; zéro ou plusieurs pistes valides peuvent être exposées. |
| `failed` | Le job n'a pas pu aller au bout pour une raison générale. |

`ready` ne signifie pas nécessairement que tous les fichiers sources étaient valides. Un fichier illisible peut être ignoré sans rendre la vidéo inutilisable.

## 3. Stocker la sélection et le statut

Créer une migration qui ajoute deux colonnes à `movies` :

```php
use App\Enums\ExternalSubtitleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->json('external_subtitles')->nullable();
            $table->string('external_subtitles_status')
                ->default(ExternalSubtitleStatus::None->value);
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->dropColumn([
                'external_subtitles',
                'external_subtitles_status',
            ]);
        });
    }
};
```

Le JSON `external_subtitles` conserve la sélection issue de l'inspection du torrent et, plus tard, le résultat de la préparation :

```json
[
    {
        "file_index": 4,
        "file_name": "Movie.2025.en.srt",
        "language": "eng",
        "output_file": "subtitles/external_00_eng.vtt"
    },
    {
        "file_index": 5,
        "file_name": "Movie.2025.fr.srt",
        "language": "fra",
        "output_file": null
    }
]
```

Un `output_file` nul signifie que le fichier n'a pas encore été traité ou qu'il était invalide. Le contrôleur n'exposera au frontend que les entrées ayant un fichier de sortie.

Ajouter les champs à `Movie` :

```php
use App\Enums\ExternalSubtitleStatus;

#[Fillable([
    // Champs existants...
    'external_subtitles',
    'external_subtitles_status',
])]
```

Puis ajouter les casts :

```php
protected function casts(): array
{
    return [
        // Casts existants...
        'external_subtitles' => 'array',
        'external_subtitles_status' => ExternalSubtitleStatus::class,
    ];
}
```

## 4. Sélectionner les sous-titres du torrent

Créer un petit service `TorrentSubtitleSelector`. Son travail est limité à trois actions :

- garder les fichiers non vides dont l'extension est `.srt` ou `.vtt` ;
- détecter une langue depuis le nom du fichier ;
- retourner des métadonnées sérialisables.

```php
<?php

namespace App\Services\Torrent;

use Illuminate\Support\Str;

final class TorrentSubtitleSelector
{
    /**
     * @param  array<int, array{index: int, fileName: string, length: int}>  $files
     * @return array<int, array{
     *     file_index: int,
     *     file_name: string,
     *     language: string,
     *     output_file: null
     * }>
     */
    public function select(array $files): array
    {
        $subtitles = [];

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file['fileName'], PATHINFO_EXTENSION));

            if (! in_array($extension, ['srt', 'vtt'], true) || $file['length'] <= 0) {
                continue;
            }

            $subtitles[] = [
                'file_index' => $file['index'],
                'file_name' => $file['fileName'],
                'language' => $this->detectLanguage($file['fileName']),
                'output_file' => null,
            ];
        }

        return $subtitles;
    }

    private function detectLanguage(string $fileName): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $normalized = Str::lower(Str::ascii($baseName));
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $languages = [];

        foreach ($tokens as $token) {
            $language = match ($token) {
                'english', 'anglais', 'eng' => 'eng',
                'french', 'francais', 'fra', 'fre' => 'fra',
                'italian', 'italien', 'ita' => 'ita',
                'german', 'deutsch', 'allemand', 'deu', 'ger' => 'deu',
                default => null,
            };

            if ($language !== null) {
                $languages[$language] = true;
            }
        }

        $detected = array_keys($languages);

        return count($detected) === 1 ? $detected[0] : 'und';
    }
}
```

Cette version considère volontairement toute langue non reconnue ou tout nom ambigu comme `und` (`undetermined`). Elle ne lit pas le contenu du sous-titre et ne prétend pas résoudre les différences d'édition entre une vidéo et un fichier externe.

Attention : selon la structure exacte renvoyée par `TorrentClient::inspect()`, les clés peuvent s'appeler `index`, `fileName` et `length`, ou autrement. Le service doit employer le même format que `TorrentVideoSelector`.

## 5. Télécharger la vidéo et les sous-titres ensemble

Injecter le sélecteur dans `DownloadMovie::handle()` :

```php
public function handle(
    TorrentClient $torrentClient,
    TorrentVideoSelector $videoSelector,
    TorrentSubtitleSelector $subtitleSelector,
): void {
    // Corps existant...
}
```

Après l'inspection et la sélection de la vidéo :

```php
$selectedVideo = $videoSelector->select($inspection['files']);
$selectedSubtitles = $subtitleSelector->select($inspection['files']);
```

Envoyer tous les index au client torrent :

```php
$selectedFileIndexes = [
    $selectedVideo['index'],
    ...array_column($selectedSubtitles, 'file_index'),
];

$downloadId = $torrentClient->startDownload(
    $movie->torrent_url,
    Storage::disk('public')->path("movies/{$movie->id}"),
    $selectedFileIndexes,
    $inspection['infoHash'],
);
```

Enregistrer la sélection en même temps que les informations existantes :

```php
Movie::query()->whereKey($movie->id)->update([
    'download_id' => $downloadId,
    'download_file_index' => $selectedVideo['index'],
    'filename' => $selectedVideo['fileName'],
    'external_subtitles' => $selectedSubtitles,
    'external_subtitles_status' => $selectedSubtitles === []
        ? ExternalSubtitleStatus::None->value
        : ExternalSubtitleStatus::Pending->value,
]);
```

Lorsqu'un nouveau téléchargement est réclamé après un échec, réinitialiser aussi ces deux champs :

```php
'external_subtitles' => null,
'external_subtitles_status' => ExternalSubtitleStatus::None->value,
```

Si l'inspection, la sélection ou le démarrage échoue, placer le statut des sous-titres à `failed` en même temps que `download_status`.

La vidéo peut toujours devenir lisible avant la fin du téléchargement complet. En revanche, les fichiers externes ne sont ouverts par FFmpeg qu'après que le client torrent annonce `completed`. Il n'existe donc aucun risque que `PrepareExternalSubtitles` lise un fichier partiel.

## 6. Ne pas étendre les responsabilités des deux pipelines existants

### `MovieConversionController`

Ne lui ajouter aucune connaissance de `PrepareExternalSubtitles`. Il continue uniquement à déclencher `DownloadMovie` ou `ConvertMovie` comme aujourd'hui.

Le contrôleur ne sait pas encore si le torrent contient des sous-titres lorsqu'il reçoit la demande. Il n'est donc pas le bon endroit pour lancer leur préparation.

### `ConvertMovie`

Ne lui injecter ni `ExternalSubtitlePreparer`, ni liste de fichiers externes, ni nouvel input FFmpeg.

Il reste responsable de :

- lire la vidéo pendant son téléchargement progressif ;
- produire le HLS vidéo/audio ;
- convertir les pistes de sous-titres déjà incorporées au conteneur ;
- placer la vidéo dans l'état `playable`, puis `converted`.

Si une première tentative d'implémentation a ajouté les sous-titres externes à la grande commande FFmpeg de `ConvertMovie`, retirer uniquement ces ajouts avant d'appliquer ce tutoriel.

## 7. Déclencher le job sur une transition atomique

Modifier `MonitorDownload` afin que le passage à `completed` soit conditionné par l'ancien état `downloading`.

Avant de construire `$update`, déterminer si des fichiers externes ont été sélectionnés :

```php
$hasExternalSubtitles = ($movie->external_subtitles ?? []) !== [];
```

Dans le cas `completed`, mettre à jour les deux statuts ensemble :

```php
'completed' => [
    'download_status' => DownloadStatus::Completed->value,
    'external_subtitles_status' => $hasExternalSubtitles
        ? ExternalSubtitleStatus::Queued->value
        : ExternalSubtitleStatus::None->value,
],
```

Dans les cas d'échec du téléchargement, employer `failed` si des sous-titres étaient attendus :

```php
'external_subtitles_status' => $hasExternalSubtitles
    ? ExternalSubtitleStatus::Failed->value
    : ExternalSubtitleStatus::None->value,
```

La requête de mise à jour doit inclure le statut précédent :

```php
$updated = Movie::query()
    ->whereKey($movie->id)
    ->where('download_id', $movie->download_id)
    ->where('download_status', DownloadStatus::Downloading->value)
    ->update($update);
```

Puis, uniquement après `$updated === 1` et uniquement pour `completed`, déclencher le nouveau job :

```php
if ($update['download_status'] === DownloadStatus::Completed->value) {
    if ($hasExternalSubtitles) {
        PrepareExternalSubtitles::dispatch($movie->id)->afterCommit();
    }

    Log::channel('my_debug')->info(/* journal existant */);

    return;
}
```

Importer les classes utilisées :

```php
use App\Enums\ExternalSubtitleStatus;
use App\Jobs\PrepareExternalSubtitles;
```

Pourquoi cette condition évite-t-elle un double lancement ? Deux jobs de surveillance peuvent avoir lu l'état `downloading`. Le premier modifie la ligne. La requête du second ne trouve alors plus de ligne encore `downloading` et retourne `0`. Lui seul qui obtient `1` a le droit de déclencher la préparation.

Il ne faut surtout pas déclencher `PrepareExternalSubtitles` dans la branche qui reprogramme simplement le prochain contrôle.

## 8. Isoler la commande FFmpeg dans un service

Créer `app/Services/Media/ExternalSubtitleConverter.php` :

```php
<?php

namespace App\Services\Media;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ExternalSubtitleConverter
{
    public function convert(string $sourcePath, string $outputPath): bool
    {
        $process = new Process([
            'ffmpeg',
            '-hide_banner',
            '-y',
            '-xerror',
            '-i',
            $sourcePath,
            '-map',
            '0:0',
            '-c:s',
            'webvtt',
            $outputPath,
        ]);
        $process->setTimeout(60);

        try {
            $process->mustRun();
        } catch (ProcessFailedException|ProcessTimedOutException) {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }

            return false;
        }

        return is_file($outputPath) && filesize($outputPath) > 0;
    }
}
```

FFmpeg effectue ici une conversion de format isolée. Il ne traite ni la vidéo, ni l'audio, et ne segmente rien. Un `.vtt` source passe également par cette commande afin de valider et normaliser sa sortie.

## 9. Créer `PrepareExternalSubtitles`

Créer `app/Jobs/PrepareExternalSubtitles.php` :

```php
<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Enums\ExternalSubtitleStatus;
use App\Models\Movie;
use App\Services\Media\ExternalSubtitleConverter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PrepareExternalSubtitles implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $movieId)
    {
        $this->onConnection('subtitles');
        $this->onQueue('subtitles');
    }

    public function handle(ExternalSubtitleConverter $converter): void
    {
        $claimed = Movie::query()
            ->whereKey($this->movieId)
            ->where('download_status', DownloadStatus::Completed->value)
            ->where('external_subtitles_status', ExternalSubtitleStatus::Queued->value)
            ->update([
                'external_subtitles_status' => ExternalSubtitleStatus::Processing->value,
            ]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $movie = Movie::query()->findOrFail($this->movieId);
            $movieDirectory = Storage::disk('public')->path("movies/{$movie->id}");
            $outputDirectory = $movieDirectory.DIRECTORY_SEPARATOR.'subtitles';

            if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true) && ! is_dir($outputDirectory)) {
                throw new \RuntimeException('Unable to create the external subtitle directory.');
            }

            $preparedSubtitles = [];

            foreach ($movie->external_subtitles ?? [] as $position => $subtitle) {
                $fileName = $subtitle['file_name'] ?? null;
                $language = $subtitle['language'] ?? 'und';

                if (! is_string($fileName) || ! $this->isSafeFileName($fileName)) {
                    Log::channel('my_debug')->warning('An unsafe external subtitle path was ignored.', [
                        'movie_id' => $movie->id,
                        'file_name' => $fileName,
                    ]);

                    $subtitle['output_file'] = null;
                    $preparedSubtitles[] = $subtitle;

                    continue;
                }

                $outputFile = sprintf('external_%02d_%s.vtt', $position, $language);
                $sourcePath = $movieDirectory.DIRECTORY_SEPARATOR.$fileName;
                $outputPath = $outputDirectory.DIRECTORY_SEPARATOR.$outputFile;

                $subtitle['output_file'] = $converter->convert($sourcePath, $outputPath)
                    ? 'subtitles/'.$outputFile
                    : null;

                $preparedSubtitles[] = $subtitle;
            }

            Movie::query()
                ->whereKey($movie->id)
                ->where('external_subtitles_status', ExternalSubtitleStatus::Processing->value)
                ->update([
                    'external_subtitles' => $preparedSubtitles,
                    'external_subtitles_status' => ExternalSubtitleStatus::Ready->value,
                ]);
        } catch (Throwable $exception) {
            Movie::query()
                ->whereKey($this->movieId)
                ->where('external_subtitles_status', ExternalSubtitleStatus::Processing->value)
                ->update([
                    'external_subtitles_status' => ExternalSubtitleStatus::Failed->value,
                ]);

            Log::channel('my_debug')->error('External subtitle preparation failed.', [
                'movie_id' => $this->movieId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    private function isSafeFileName(string $fileName): bool
    {
        return $fileName !== ''
            && basename($fileName) === $fileName
            && ! str_contains($fileName, '\\');
    }
}
```

Ce snippet suppose que `TorrentClient` place tous les fichiers sélectionnés directement dans `storage/app/public/movies/{movie}` avec leur nom de base, ce qui correspond au comportement attendu par `filename` aujourd'hui. Si le client conserve les sous-répertoires du torrent, il faut centraliser cette résolution dans `MovieInputResolver` et valider que le chemin résolu reste à l'intérieur du répertoire du film.

L'idempotence est assurée une seconde fois dans le job : seul un film `completed` dont le statut est exactement `queued` peut passer à `processing`. Un job dupliqué quitte immédiatement.

## 10. Utiliser une queue dédiée

Ajouter une connexion dans `config/queue.php`, sur le même modèle que `monitoring` et `conversions` :

```php
'subtitles' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => 'subtitles',
    'retry_after' => 180,
    'after_commit' => false,
],
```

Puis démarrer un worker qui consomme réellement cette connexion et cette queue :

```bash
php artisan queue:work subtitles --queue=subtitles --tries=1 --timeout=120
```

La configuration de production, Docker ou Supervisor doit lancer l'équivalent. Sans ce worker, les films resteront à `external_subtitles_status = queued`.

Une variante plus petite consiste à utiliser la connexion et la queue `monitoring` dans le constructeur du job. Elle évite un worker supplémentaire, mais une conversion FFmpeg lente bloque alors les contrôles de téléchargement des autres films. La queue `subtitles` est donc préférable.

## 11. Exposer uniquement les pistes prêtes

Dans `MovieController::show()`, ajouter le statut et la liste préparée à `moviePageData`.

Importer `Storage`, puis construire les données :

```php
'external_subtitles_status' => $movie->external_subtitles_status->value,
'external_subtitles' => collect($movie->external_subtitles ?? [])
    ->filter(fn (array $subtitle): bool => is_string($subtitle['output_file'] ?? null))
    ->map(fn (array $subtitle): array => [
        'id' => $subtitle['file_index'],
        'label' => $subtitle['file_name'].' — External',
        'language' => $subtitle['language'],
        'url' => Storage::disk('public')->url(
            "movies/{$movie->id}/{$subtitle['output_file']}"
        ),
    ])
    ->values()
    ->all(),
```

Le frontend ne reçoit ainsi jamais un chemin local du serveur et ne voit pas les sorties invalides.

## 12. Ajouter les pistes au lecteur sans redémarrer Hls.js

Dans `resources/js/pages/movies/show.tsx`, ajouter les types :

```tsx
type ExternalSubtitleStatus =
    | 'none'
    | 'pending'
    | 'queued'
    | 'processing'
    | 'ready'
    | 'failed';

type ExternalSubtitleTrack = {
    id: number;
    label: string;
    language: string;
    url: string;
};
```

Étendre `MoviePageData` :

```tsx
external_subtitles_status: ExternalSubtitleStatus;
external_subtitles: ExternalSubtitleTrack[];
```

Étendre les propriétés de `HlsPlayer` :

```tsx
function HlsPlayer({
    src,
    preferredlanguage,
    externalSubtitles,
}: {
    src: string;
    preferredlanguage: string | null;
    externalSubtitles: ExternalSubtitleTrack[];
}) {
```

Ne pas ajouter `externalSubtitles` aux dépendances du `useEffect` qui instancie Hls.js. Sinon, l'arrivée des pistes détruirait et recréerait l'instance HLS pendant la lecture.

Remplacer la balise `<video />` auto-fermante par :

```tsx
return (
    <video ref={videoRef} controls className="w-full">
        {externalSubtitles.map((subtitle) => (
            <track
                key={subtitle.id}
                kind="subtitles"
                src={subtitle.url}
                srcLang={subtitle.language}
                label={subtitle.label}
            />
        ))}
    </video>
);
```

Puis fournir la liste depuis la page :

```tsx
<HlsPlayer
    src={`/storage/movies/${moviePageData.id}/hls/${moviePageData.conversion_attempt}/index.m3u8`}
    preferredlanguage={moviePageData.preferredlanguage}
    externalSubtitles={moviePageData.external_subtitles}
/>
```

Les pistes incorporées restent gérées par Hls.js à partir du manifeste. Les pistes externes sont gérées par le navigateur à partir des balises `<track>`. Elles apparaissent ensemble dans le menu de sous-titres du lecteur natif.

## 13. Continuer le polling après que la vidéo est lisible

Le polling actuel s'arrête dès que `conversion_status` devient `playable`. Il faut maintenant attendre deux conditions indépendantes :

- la vidéo est lisible ;
- les sous-titres externes sont dans un état terminal.

Conserver le `usePoll` existant, mais remplacer la condition d'arrêt par :

```tsx
const videoIsPlayable =
    moviePageData.conversion_status === 'playable' ||
    moviePageData.conversion_status === 'converted';

const subtitlesAreTerminal = ['none', 'ready', 'failed'].includes(
    moviePageData.external_subtitles_status,
);

useEffect(() => {
    if (
        isUnsupported ||
        moviePageData.conversion_status === 'failed' ||
        (videoIsPlayable && subtitlesAreTerminal)
    ) {
        stop();
    }
}, [
    isUnsupported,
    moviePageData.conversion_status,
    moviePageData.external_subtitles_status,
    stop,
    subtitlesAreTerminal,
    videoIsPlayable,
]);
```

Cette logique produit le comportement suivant :

| Vidéo | Sous-titres | Polling |
|---|---|---|
| en conversion | n'importe quel état | continue |
| lisible | `pending`, `queued` ou `processing` | continue |
| lisible | `none`, `ready` ou `failed` | s'arrête |
| `failed` ou torrent non supporté | n'importe quel état | s'arrête |

Quand Inertia reçoit `ready`, React ajoute les `<track>` sans modifier la source HLS et sans interrompre la lecture en cours.

## 14. Langue préférée et sélection dans le lecteur

Tous les sous-titres trouvés étant téléchargés, les pistes anglaises et celles correspondant éventuellement à la préférence de l'utilisateur sont disponibles sans logique serveur supplémentaire.

Le champ `language` sert à renseigner `srcLang` et à afficher un libellé intelligible. Le critère demande que la piste soit sélectionnable, pas qu'elle soit automatiquement activée. La sélection manuelle dans le menu du lecteur suffit donc pour cette étape.

Si une activation automatique est ajoutée ultérieurement, elle devra parcourir `video.textTracks` après l'arrivée des `<track>` et activer en priorité la langue préférée, puis éventuellement l'anglais. Cette amélioration doit rester séparée du pipeline de téléchargement.

## 15. Cas limites et comportement attendu

- Un fichier de sous-titres plus long que la vidéo ne prolonge pas la vidéo. Les cues situés après sa fin ne sont simplement jamais affichés.
- Un fichier plus court cesse d'afficher des cues avant la fin de la vidéo.
- Une mauvaise synchronisation entre une édition du film et un sous-titre externe est hors périmètre.
- Un torrent contenant plusieurs vidéos peut contenir des sous-titres destinés à une autre vidéo. Cette association sémantique est également hors périmètre : toutes les pistes sélectionnées sont proposées et l'utilisateur choisit.
- Un fichier externe invalide est ignoré individuellement. Les autres pistes et la vidéo restent disponibles.
- Une erreur générale du job place le statut à `failed`. Elle ne doit jamais faire repasser `conversion_status` à `failed`, car la vidéo reste lisible.
- Une piste externe n'est exposée qu'après la création réussie de son fichier WebVTT.

## 16. Tests à ajouter

Les tests doivent reprendre le vocabulaire du critère d'évaluation, afin de démontrer directement sa conformité.

### `TorrentSubtitleSelectorTest`

- sélectionne tous les `.srt` et `.vtt` non vides ;
- ignore les autres extensions ;
- détecte `eng`, `fra`, `ita`, `deu` ;
- utilise `und` sans indice ou avec plusieurs langues reconnues.

### `DownloadMovieTest`

- envoie au client torrent l'index de la vidéo et tous les index de sous-titres ;
- persiste les métadonnées ;
- utilise `pending` lorsqu'il existe des sous-titres et `none` sinon.

### `MonitorDownloadTest`

- la transition atomique vers `completed` place les sous-titres à `queued` ;
- elle déclenche exactement un `PrepareExternalSubtitles` ;
- un second moniteur concurrent ne déclenche rien ;
- un téléchargement échoué ne déclenche pas la préparation.

### `PrepareExternalSubtitlesTest`

- réclame atomiquement un film `completed/queued` ;
- produit une sortie `.vtt` pour chaque fichier valide ;
- ignore un fichier invalide sans perdre les sorties valides ;
- termine à `ready` ;
- refuse de travailler avant `download_status = completed` ;
- un job dupliqué ne reconvertit pas les fichiers.

### `MovieControllerTest`

- expose le statut séparé ;
- expose uniquement les fichiers préparés ;
- génère des URL publiques et ne divulgue aucun chemin local.

Enfin, vérifier manuellement dans les navigateurs demandés par le projet qu'une piste apparue après le passage de la vidéo à `playable` devient sélectionnable sans interrompre la lecture.

Exécuter les tests ciblés, puis le formatage et la vérification TypeScript :

```bash
php artisan test --compact tests/Unit/Services/Torrent/TorrentSubtitleSelectorTest.php
php artisan test --compact tests/Feature/Jobs/DownloadMovieTest.php
php artisan test --compact tests/Feature/Jobs/MonitorDownloadTest.php
php artisan test --compact tests/Feature/Jobs/PrepareExternalSubtitlesTest.php
php artisan test --compact tests/Feature/Http/Controllers/MovieControllerTest.php
vendor/bin/pint --dirty --format agent
npm run types
```

Adapter les chemins aux emplacements réels des tests existants. La commande `pint --dirty` doit être lancée après les modifications PHP, pas pendant la simple lecture de ce tutoriel.

## 17. Ordre d'implémentation conseillé

1. Ajouter l'enum, la migration et les casts du modèle.
2. Ajouter et tester `TorrentSubtitleSelector`.
3. Modifier `DownloadMovie` pour télécharger et persister les fichiers externes.
4. Ajouter et tester `ExternalSubtitleConverter`.
5. Ajouter le job et la queue `subtitles`.
6. Modifier et tester la transition atomique de `MonitorDownload`.
7. Exposer le statut et les pistes dans `MovieController`.
8. Ajouter les `<track>` et corriger l'arrêt du polling frontend.
9. Lancer un worker `subtitles` et effectuer le test manuel de bout en bout.

Le résultat conserve deux pipelines indépendants : le HLS peut devenir lisible rapidement, puis les sous-titres externes apparaissent dès que le téléchargement complet et leur courte conversion sont terminés.
