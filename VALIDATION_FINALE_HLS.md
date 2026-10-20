# Empêcher une conversion HLS tronquée d'être déclarée terminée

## Le problème que cette modification doit résoudre

Aujourd'hui, `ConvertMovie` considère la conversion comme terminée lorsque `HlsConverter` rend la main sans erreur. En pratique, cela revient presque à faire confiance au code de sortie de FFmpeg.

Or FFmpeg peut recevoir une fin de flux HTTP prématurée, fermer correctement les fichiers qu'il a déjà produits, puis retourner le code `0`. Le résultat ressemble alors à une conversion valide :

```m3u8
#EXTM3U
#EXT-X-VERSION:6
#EXTINF:0.250267,
video_00000.ts
#EXT-X-ENDLIST
```

Cette playlist est syntaxiquement terminée. Son segment existe. Pourtant elle ne contient que `0,25` seconde d'un film de `7 814` secondes.

Il faut donc distinguer trois choses :

1. FFmpeg s'est arrêté sans signaler d'erreur.
2. FFmpeg a fermé proprement la playlist avec `#EXT-X-ENDLIST`.
3. La playlist contient réellement la totalité du film.

Les deux premiers points ne prouvent pas le troisième. Cette modification ajoute cette preuve manquante avant le passage à `ConversionStatus::Converted`.

## Ce qui sera vérifié

Après le retour de FFmpeg, Laravel devra :

- connaître la durée totale annoncée par le conteneur source grâce à `ffprobe` ;
- lire la playlist vidéo `video.m3u8` ;
- additionner toutes ses durées `#EXTINF` ;
- vérifier que chaque segment indiqué par la playlist existe et n'est pas vide ;
- vérifier que la playlist se termine par `#EXT-X-ENDLIST` ;
- refuser la conversion si la durée produite est trop inférieure à la durée source.

La même validation s'applique aux sources MP4 et MKV. À ce niveau, le conteneur d'entrée n'a plus d'importance : Laravel compare une durée source avec une sortie HLS.

## Comprendre les différentes playlists du projet

Le dossier HLS contient plusieurs types de fichiers :

- `index.m3u8` est la playlist maître publiée par `HlsMasterPlaylistBuilder`. Elle indique au lecteur où trouver la vidéo et les sous-titres. Elle ne contient pas la durée du film.
- `video.m3u8` est la playlist média de la vidéo. Chaque `#EXTINF` décrit la durée du segment `.ts` qui suit. C'est elle qu'il faut comparer à la durée source.
- les fichiers `subtitle_XX_langue.m3u8` décrivent des segments WebVTT. Leur chronologie est naturellement discontinue et ne représente pas la durée de la vidéo.

Dans cette étape, « tous les segments référencés » désigne tous les segments `.ts` référencés par `video.m3u8`. Les playlists de sous-titres ne participent pas à la décision `converted` et restent hors du validateur de complétude vidéo.

## Les fichiers concernés

L'implémentation touche six endroits :

| Fichier | Rôle de la modification |
|---|---|
| `app/Data/MediaInfo.php` | transporter la durée source avec les pistes détectées |
| `app/Services/Media/MediaProbe.php` | demander et valider cette durée avec `ffprobe` |
| `config/media.php` | définir la tolérance admise |
| `app/Services/Media/HlsCompletionValidator.php` | analyser la sortie HLS finale |
| `app/Jobs/ConvertMovie.php` | appeler le validateur avant le statut `converted` |
| tests existants et nouveau test unitaire | protéger ce comportement contre les régressions |

## 1. Ajouter la durée à `MediaInfo`

`MediaProbe` retourne actuellement un objet `MediaInfo` contenant uniquement les pistes. Ce même objet peut aussi transporter la durée, ce qui évite d'exécuter une seconde fois `ffprobe` après la conversion.

Dans `app/Data/MediaInfo.php`, remplacer seulement le constructeur. Les méthodes `videos()`, `audios()` et `subtitles()` restent inchangées.

```php
/** @param list<MediaStream> $streams */
public function __construct(
    public array $streams,
    public float $durationSeconds,
) {}
```

Cette durée est obligatoire. Lui donner une valeur par défaut masquerait le cas où `ffprobe` n'a pas réussi à déterminer la durée du film.

Deux tests construisent directement cet objet dans `tests/Unit/TrackSelectorTest.php`. Leur durée n'intervient pas dans la sélection des pistes, mais ils doivent respecter le nouveau contrat :

```php
return new MediaInfo(
    streams: [
        // Les MediaStream déjà présents dans le test.
    ],
    durationSeconds: 60.0,
);
```

Faire la même adaptation dans le test « rejects media without a video track ».

## 2. Récupérer la durée globale avec `ffprobe`

`MediaProbe` utilise déjà `ffprobe` pour découvrir les pistes. L'option `-show_format` ajoute les informations globales du conteneur au même JSON, notamment `format.duration`.

Dans `app/Services/Media/MediaProbe.php`, ajouter `-show_format` à la commande existante :

```php
$process = new Process([
    (string) config('media.ffprobe_binary'),
    '-v',
    'error',
    ...$movieInput->inputOptions(),
    '-show_streams',
    '-show_format',
    '-of',
    'json',
    $movieInput->location,
]);
```

Après la validation de `$decoded['streams']` et avant la construction des `MediaStream`, lire la durée :

```php
$rawDuration = $decoded['format']['duration'] ?? null;

if (! is_numeric($rawDuration)) {
    throw new MediaConversionException(
        'FFprobe did not return a usable source duration.',
    );
}

$durationSeconds = (float) $rawDuration;

if (! is_finite($durationSeconds) || $durationSeconds <= 0) {
    throw new MediaConversionException(
        'FFprobe returned an invalid source duration.',
    );
}
```

Enfin, remplacer le retour actuel :

```php
return new MediaInfo(
    streams: $streams,
    durationSeconds: $durationSeconds,
);
```

### Pourquoi échouer si la durée manque ?

Une valeur absente, `N/A`, nulle ou négative ne permet aucune comparaison fiable. Continuer avec une durée fictive reviendrait à désactiver silencieusement la protection. Dans ce cas, il vaut mieux produire une erreur claire et conserver le film hors de l'état `converted`.

Ce comportement est identique pour un fichier local et pour une URL du client torrent. MP4 expose généralement cette information dans ses métadonnées `moov`; Matroska l'expose dans les informations de son segment. Le code ne cherche pas ces structures lui-même : il utilise la valeur normalisée par `ffprobe`.

## 3. Définir ce que signifie « suffisamment complet »

Les durées ne doivent pas être comparées avec une égalité stricte. Le découpage aux images clés et les horodatages des codecs peuvent créer un petit écart légitime.

Ajouter ces deux valeurs dans la section `hls` de `config/media.php` :

```php
'hls' => [
    'segment_duration' => 6,
    'bandwidth' => 1_700_000,
    'completion_minimum_ratio' => 0.99,
    'completion_tolerance_segments' => 2,
],
```

La durée minimale acceptable sera calculée ainsi :

```php
$minimumAcceptedDuration = max(
    $sourceDurationSeconds * 0.99,
    $sourceDurationSeconds - (2 * $segmentDurationSeconds),
);
```

Les deux protections se complètent :

| Durée source | Limite de 99 % | Limite de deux segments | Minimum finalement exigé |
|---:|---:|---:|---:|
| 60 s | 59,4 s | 48 s | 59,4 s |
| 7 814 s | 7 735,86 s | 7 802 s | 7 802 s |

Pour un film long, la perte maximale est donc de 12 secondes avec des segments de 6 secondes. Pour une courte vidéo, la règle des 99 % évite que la tolérance fixe de 12 secondes devienne trop permissive.

Ce seuil ne cherche pas à certifier chaque image. Il sert à détecter une sortie manifestement incomplète tout en tolérant les petits écarts normaux du transcodage.

## 4. Créer `HlsCompletionValidator`

Créer `app/Services/Media/HlsCompletionValidator.php` avec le contenu suivant :

```php
<?php

namespace App\Services\Media;

use App\Exceptions\MediaConversionException;

final class HlsCompletionValidator
{
    public function assertComplete(
        string $directory,
        float $sourceDurationSeconds,
    ): void {
        if (! is_finite($sourceDurationSeconds) || $sourceDurationSeconds <= 0) {
            throw new MediaConversionException('The source duration is invalid.');
        }

        $videoDurationSeconds = $this->inspectVideoPlaylist($directory);

        $segmentDurationSeconds = (float) config('media.hls.segment_duration', 6);
        $minimumRatio = (float) config('media.hls.completion_minimum_ratio', 0.99);
        $toleranceSegments = (int) config('media.hls.completion_tolerance_segments', 2);

        $minimumAcceptedDuration = max(
            $sourceDurationSeconds * $minimumRatio,
            $sourceDurationSeconds - ($segmentDurationSeconds * $toleranceSegments),
        );

        if ($videoDurationSeconds < $minimumAcceptedDuration) {
            throw new MediaConversionException(sprintf(
                'The HLS output is truncated: %.3f seconds produced for a %.3f-second source; at least %.3f seconds were required.',
                $videoDurationSeconds,
                $sourceDurationSeconds,
                $minimumAcceptedDuration,
            ));
        }
    }

    private function inspectVideoPlaylist(string $directory): float
    {
        $playlistName = 'video.m3u8';
        $playlistPath = $directory.DIRECTORY_SEPARATOR.$playlistName;

        if (! is_file($playlistPath)) {
            throw new MediaConversionException(
                "The HLS playlist [{$playlistName}] is missing.",
            );
        }

        $lines = file($playlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false || $lines === []) {
            throw new MediaConversionException(
                "The HLS playlist [{$playlistName}] is empty or unreadable.",
            );
        }

        if (trim($lines[0]) !== '#EXTM3U') {
            throw new MediaConversionException(
                "The HLS playlist [{$playlistName}] has no EXTM3U header.",
            );
        }

        $pendingDuration = null;
        $playlistDurationSeconds = 0.0;
        $segmentCount = 0;
        $hasEndList = false;

        foreach (array_slice($lines, 1) as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if ($hasEndList) {
                throw new MediaConversionException(
                    "The HLS playlist [{$playlistName}] contains data after EXT-X-ENDLIST.",
                );
            }

            if ($line === '#EXT-X-ENDLIST') {
                if ($pendingDuration !== null) {
                    throw new MediaConversionException(
                        "The HLS playlist [{$playlistName}] ends before its last segment.",
                    );
                }

                $hasEndList = true;

                continue;
            }

            if (str_starts_with($line, '#EXTINF:')) {
                if ($pendingDuration !== null) {
                    throw new MediaConversionException(
                        "The HLS playlist [{$playlistName}] contains consecutive EXTINF entries.",
                    );
                }

                if (preg_match('/\A#EXTINF:(\d+(?:\.\d+)?),/', $line, $matches) !== 1) {
                    throw new MediaConversionException(
                        "The HLS playlist [{$playlistName}] contains an invalid EXTINF duration.",
                    );
                }

                $pendingDuration = (float) $matches[1];

                if (! is_finite($pendingDuration) || $pendingDuration <= 0) {
                    throw new MediaConversionException(
                        "The HLS playlist [{$playlistName}] contains a non-positive segment duration.",
                    );
                }

                continue;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            if ($pendingDuration === null) {
                throw new MediaConversionException(
                    "The HLS playlist [{$playlistName}] contains a segment without EXTINF.",
                );
            }

            if (preg_match('/\Avideo_[0-9]{5}\.ts\z/D', $line) !== 1) {
                throw new MediaConversionException(
                    "The HLS playlist [{$playlistName}] contains an invalid segment path.",
                );
            }

            $segmentPath = $directory.DIRECTORY_SEPARATOR.$line;
            $segmentSize = is_file($segmentPath) ? filesize($segmentPath) : false;

            if ($segmentSize === false) {
                throw new MediaConversionException(
                    "The HLS segment [{$line}] is missing or unreadable.",
                );
            }

            if ($segmentSize === 0) {
                throw new MediaConversionException(
                    "The HLS segment [{$line}] is empty.",
                );
            }

            $playlistDurationSeconds += $pendingDuration;
            $pendingDuration = null;
            $segmentCount++;
        }

        if ($pendingDuration !== null) {
            throw new MediaConversionException(
                "The HLS playlist [{$playlistName}] contains an EXTINF without a segment.",
            );
        }

        if ($segmentCount === 0) {
            throw new MediaConversionException(
                "The HLS playlist [{$playlistName}] contains no segment.",
            );
        }

        if (! $hasEndList) {
            throw new MediaConversionException(
                "The HLS playlist [{$playlistName}] has no EXT-X-ENDLIST marker.",
            );
        }

        return $playlistDurationSeconds;
    }
}
```

### Ce que fait réellement cette classe

`inspectVideoPlaylist()` lit la playlist vidéo comme une suite de couples :

```text
#EXTINF:6.006,
video_00042.ts
```

Une durée `#EXTINF` est mémorisée, puis le prochain chemin non commenté doit être son segment. Le segment est accepté seulement si son nom correspond exactement au format produit par `HlsCommandBuilder`, si le fichier existe et si sa taille est supérieure à zéro. La durée n'est ajoutée au total qu'après ces contrôles.

La restriction sur le nom empêche également une playlist contenant `../fichier.ts` ou une URL distante de faire sortir le validateur du dossier de la conversion.

Le marqueur `#EXT-X-ENDLIST` doit être présent à la fin. Une playlist encore ouverte ou contenant des données après ce marqueur est refusée.

## 5. Appeler le validateur au bon endroit

Dans `app/Jobs/ConvertMovie.php`, ajouter l'import :

```php
use App\Services\Media\HlsCompletionValidator;
```

Ajouter ensuite le service aux paramètres de `handle()`, juste après `HlsReadinessChecker` :

```php
public function handle(
    MediaProbe $probe,
    TrackSelector $trackSelector,
    HlsCommandBuilder $hlsCommandBuilder,
    HlsReadinessChecker $hlsReadinessChecker,
    HlsCompletionValidator $hlsCompletionValidator,
    HlsMasterPlaylistBuilder $hlsMasterPlaylistBuilder,
    HlsConverter $hlsConverter,
    MovieInputResolver $movieInputResolver,
): void {
```

Aucun enregistrement manuel dans un service provider n'est nécessaire : Laravel résout automatiquement cette classe concrète lorsqu'il exécute le job.

Le résultat de `MediaProbe` doit maintenant être conservé, car sa durée sera utilisée après la conversion. Remplacer :

```php
$tracksSelected = $trackSelector->select($probe->probe($fileOrStream));
```

par :

```php
$mediaInfo = $probe->probe($fileOrStream);
$tracksSelected = $trackSelector->select($mediaInfo);
```

Après `$publishWhenReady()` et le contrôle de `$published`, appeler le validateur juste avant la requête qui écrit `ConversionStatus::Converted` :

```php
$publishWhenReady();

if (! $published) {
    throw new \RuntimeException(
        'FFmpeg finished without producing avaible hls segment.',
    );
}

$hlsCompletionValidator->assertComplete(
    directory: $outputDirectory,
    sourceDurationSeconds: $mediaInfo->durationSeconds,
);

Movie::query()
    ->whereKey($movie->id)
    ->where('conversion_attempt', $attempt)
    ->where('conversion_status', ConversionStatus::Playable->value)
    ->update([
        'conversion_status' => ConversionStatus::Converted->value,
        'conversion_completed_at' => now(),
    ]);
```

L'emplacement est essentiel. Le validateur doit s'exécuter :

- après FFmpeg, car il doit lire la playlist finale ;
- après le dernier `$publishWhenReady()`, pour conserver le comportement actuel de publication ;
- avant l'écriture de `converted`, car cette écriture est précisément ce qu'il doit protéger.

Si la validation échoue, elle lance `MediaConversionException`. Le `catch (Throwable $exception)` déjà présent dans le job enregistre alors `ConversionStatus::Failed`, conserve le message dans `conversion_error` et relance l'exception. Il n'est donc pas nécessaire d'ajouter un second traitement d'erreur.

## 6. Adapter les appels manuels du job dans les tests

En production, Laravel injecte automatiquement le nouveau service. Dans `tests/Feature/Movies/ConvertMovieTest.php`, le job est cependant appelé manuellement plusieurs fois.

Ajouter l'import :

```php
use App\Services\Media\HlsCompletionValidator;
```

Puis ajouter `new HlsCompletionValidator` après `new HlsReadinessChecker` dans chaque appel existant :

```php
(new ConvertMovie($movie->id))->handle(
    new MediaProbe,
    new TrackSelector,
    new HlsCommandBuilder,
    new HlsReadinessChecker,
    new HlsCompletionValidator,
    new HlsMasterPlaylistBuilder,
    new HlsConverter,
    app(MovieInputResolver::class),
);
```

Le test « conversion reaches converted » devient alors un véritable test d'intégration de la validation finale : sa fixture MKV ne pourra atteindre `converted` que si `ffprobe` fournit une durée et si le HLS produit est complet.

## 7. Tester le cas exact qui a provoqué le faux succès

Créer `tests/Unit/HlsCompletionValidatorTest.php`. Le test essentiel doit reproduire le cas observé : un segment non vide, un `#EXT-X-ENDLIST`, un code structurellement propre, mais une durée ridiculement courte.

```php
<?php

use App\Exceptions\MediaConversionException;
use App\Services\Media\HlsCompletionValidator;
use Illuminate\Support\Facades\Storage;

test('a closed but truncated hls playlist is rejected', function () {
    Storage::fake('public');

    $disk = Storage::disk('public');
    $directory = 'validation/truncated';

    $disk->put("{$directory}/video_00000.ts", 'not-empty');
    $disk->put("{$directory}/video.m3u8", <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:6
#EXTINF:0.250267,
video_00000.ts
#EXT-X-ENDLIST
M3U8);

    expect(fn () => (new HlsCompletionValidator)->assertComplete(
        directory: $disk->path($directory),
        sourceDurationSeconds: 7814.634,
    ))->toThrow(MediaConversionException::class, 'The HLS output is truncated');
});
```

Ajouter ensuite des tests ciblés pour les autres garanties :

- une playlist vidéo complète est acceptée ;
- l'absence de `#EXT-X-ENDLIST` est refusée ;
- un segment annoncé mais absent est refusé ;
- un segment de zéro octet est refusé ;
- un chemin comme `../video_00000.ts` est refusé.

Ces tests ne lancent pas FFmpeg. Ils testent uniquement la règle métier du validateur avec de petits fichiers créés sur le disque factice de Laravel.

Dans `tests/Unit/MediaProbeTest.php`, ajouter également un test utilisant `tests/Fixtures/test2.mkv` :

```php
test('the media probe returns the source duration', function () {
    $mediaInfo = (new MediaProbe)->probe(new MovieInput(
        location: base_path('tests/Fixtures/test2.mkv'),
        isStream: false,
    ));

    expect($mediaInfo->durationSeconds)->toBeGreaterThan(0.0);
});
```

Ce test vérifie séparément que `-show_format` est bien présent et que `format.duration` est correctement transformé en `float`.

## 8. Vérifier l'ensemble

Une fois l'implémentation terminée, exécuter les tests directement concernés :

```bash
php artisan test --compact tests/Unit/HlsCompletionValidatorTest.php
php artisan test --compact tests/Unit/MediaProbeTest.php
php artisan test --compact tests/Unit/TrackSelectorTest.php
php artisan test --compact tests/Feature/Movies/ConvertMovieTest.php
```

Puis appliquer le formatage attendu par le projet :

```bash
vendor/bin/pint --dirty --format agent
```

Le résultat attendu est le suivant :

- les conversions MP4 et MKV complètes continuent d'atteindre `converted` ;
- une playlist de 0,25 seconde pour une source de 7 814 secondes provoque une `MediaConversionException` ;
- le film concerné passe à `failed`, même si FFmpeg avait retourné `0` ;
- `conversion_completed_at` reste vide en cas d'échec ;
- le message enregistré indique la durée produite, la durée source et le minimum exigé.

## Ce que cette étape ne résout pas

Cette validation empêche un faux succès, mais elle ne répare pas le transport HTTP qui a provoqué la troncature du MKV. Le flux pourra encore être interrompu tant que les politiques MP4/MKV ne seront pas différenciées entre le client torrent et FFmpeg.

C'est volontaire : cette première protection établit une règle indépendante du transport. Quelle que soit la cause d'une interruption actuelle ou future, aucune sortie incomplète ne pourra plus être enregistrée comme une conversion terminée.
