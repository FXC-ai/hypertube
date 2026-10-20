# Refuser une conversion HLS tronquée avec ffprobe

## Objectif

Un film ne doit jamais passer à `ConversionStatus::Converted` lorsque FFmpeg n'a produit qu'une petite partie de sa durée, même si FFmpeg retourne le code de sortie `0`.

La solution retenue est volontairement simple :

```text
ffprobe sur la source
        ↓
durée attendue
        ↓
conversion FFmpeg
        ↓
ffprobe sur video.m3u8
        ↓
durée réellement produite
        ↓
comparaison avant le statut converted
```

Cette solution ne lit pas le flux `-progress`, ne crée pas de fichier de progression et ne parcourt pas manuellement tous les `#EXTINF`. Elle utilise `ffprobe`, l'outil conçu pour interroger la durée d'un média.

## Pourquoi cette vérification est nécessaire

FFmpeg retourne `0` lorsqu'il a correctement traité tout ce qu'il considère comme son entrée. Si une connexion HTTP se ferme prématurément mais ressemble à une fin de fichier normale, FFmpeg peut :

- convertir les quelques images reçues ;
- fermer `video.m3u8` avec `#EXT-X-ENDLIST` ;
- retourner le code `0`.

Il ne sait pas nécessairement que le film devait durer encore deux heures.

Dans l'incident observé :

```text
durée de la source : 7 814,634 secondes
durée du HLS       :     0,250 seconde
code de sortie     :     0
```

La comparaison des durées transforme ce faux succès en échec explicite.

## Architecture retenue

Chaque classe conserve une responsabilité claire :

| Classe | Responsabilité |
|---|---|
| `MediaProbe` | demander à `ffprobe` les pistes et la durée d'un média |
| `HlsConverter` | exécuter FFmpeg, publier progressivement et contrôler son code de sortie |
| `HlsCompletionValidator` | vérifier la fermeture de la playlist et comparer les durées |
| `ConvertMovie` | organiser ces opérations avant d'écrire le statut final |

Un petit validateur séparé est préférable à une longue condition dans le job : la règle est lisible, testable sans lancer FFmpeg et ne surcharge pas `MediaProbe`.

## Comportement final

Pour atteindre `converted`, toutes ces conditions devront être vraies :

1. `ffprobe` peut déterminer une durée positive pour la source.
2. FFmpeg termine avec le code `0`.
3. Le dernier appel de publication a trouvé un HLS lisible.
4. `video.m3u8` se termine par `#EXT-X-ENDLIST`.
5. `ffprobe` peut déterminer une durée positive pour `video.m3u8`.
6. La durée HLS est suffisamment proche de la durée source.

Cette règle est identique pour une source MP4 et une source MKV.

## 1. Ajouter la durée à `MediaInfo`

`MediaProbe` retourne actuellement les pistes dans `MediaInfo`. Il doit également retourner la durée du média analysé.

Dans `app/Data/MediaInfo.php`, conserver les méthodes `videos()`, `audios()` et `subtitles()`, mais utiliser ce constructeur :

```php
/** @param list<MediaStream> $streams */
public function __construct(
    public array $streams,
    public float $durationSeconds,
) {}
```

La durée n'a pas de valeur par défaut. Si elle manque, la complétude de la conversion ne peut pas être prouvée.

### Adapter `TrackSelectorTest`

`tests/Unit/TrackSelectorTest.php` construit directement deux objets `MediaInfo`.

La fixture principale devient :

```php
function mediaInfoFixture(): MediaInfo
{
    return new MediaInfo(
        streams: [
            new MediaStream(0, 'video', 'h264', 'und', '', []),
            new MediaStream(1, 'audio', 'aac', 'eng', '', []),
            new MediaStream(2, 'subtitle', 'subrip', 'eng', '', []),
            new MediaStream(3, 'subtitle', 'subrip', 'fra', '', []),
            new MediaStream(4, 'subtitle', 'ass', 'deu', '', []),
            new MediaStream(5, 'subtitle', 'mov_text', 'ita', '', []),
            new MediaStream(6, 'subtitle', 'subrip', 'spa', '', []),
        ],
        durationSeconds: 60.0,
    );
}
```

Dans le test sans piste vidéo :

```php
$media = new MediaInfo(
    streams: [new MediaStream(1, 'audio', 'aac', 'eng', '', [])],
    durationSeconds: 60.0,
);
```

Cette durée est arbitraire dans ces tests, car `TrackSelector` ne l'utilise pas.

## 2. Faire retourner la durée par `MediaProbe`

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

`-show_streams` fournit les pistes. `-show_format` ajoute les informations globales du conteneur, dont `format.duration`.

Après la validation actuelle de `$decoded['streams']`, ajouter :

```php
$rawDuration = $decoded['format']['duration'] ?? null;

if (! is_numeric($rawDuration)) {
    throw new MediaConversionException(
        'FFprobe did not return a usable media duration.',
    );
}

$durationSeconds = (float) $rawDuration;

if (! is_finite($durationSeconds) || $durationSeconds <= 0) {
    throw new MediaConversionException(
        'FFprobe returned an invalid media duration.',
    );
}
```

Puis remplacer le retour final :

```php
return new MediaInfo(
    streams: $streams,
    durationSeconds: $durationSeconds,
);
```

Le message parle volontairement de « media duration » et non de « source duration » : la même méthode sera ensuite utilisée sur la source et sur la sortie HLS.

### Pourquoi réutiliser `MediaProbe` pour le HLS ?

`MovieInput` peut représenter un fichier local. Après FFmpeg, il suffit donc de lui donner le chemin local de `video.m3u8` :

```php
new MovieInput(
    location: $outputDirectory.DIRECTORY_SEPARATOR.'video.m3u8',
    isStream: false,
)
```

`ffprobe` suit les références relatives aux segments et retourne la durée HLS dans le même champ `format.duration`. Aucun second parseur n'est nécessaire.

## 3. Définir une tolérance raisonnable

Une sortie transcodée peut être légèrement plus courte que la durée annoncée par son conteneur à cause des horodatages et du découpage aux images clés. Une égalité stricte serait donc fragile.

Dans la section `hls` de `config/media.php`, ajouter :

```php
'hls' => [
    'segment_duration' => 6,
    'bandwidth' => 1_700_000,
    'completion_minimum_ratio' => 0.99,
    'completion_tolerance_segments' => 2,
],
```

La perte autorisée sera la plus petite de ces deux valeurs :

```text
1 % de la durée source
ou
la durée de deux segments HLS
```

Avec des segments de 6 secondes :

| Durée source | 1 % | Deux segments | Perte autorisée |
|---:|---:|---:|---:|
| 60 s | 0,6 s | 12 s | 0,6 s |
| 7 814,634 s | 78,146 s | 12 s | 12 s |

Cette combinaison protège les vidéos courtes sans autoriser une perte trop importante sur les films longs.

## 4. Créer le validateur final

Créer `app/Services/Media/HlsCompletionValidator.php` avec ce contenu :

```php
<?php

namespace App\Services\Media;

use App\Exceptions\MediaConversionException;

final class HlsCompletionValidator
{
    public function assertComplete(
        string $playlistPath,
        float $sourceDurationSeconds,
        float $hlsDurationSeconds,
    ): void {
        if (! is_finite($sourceDurationSeconds) || $sourceDurationSeconds <= 0) {
            throw new MediaConversionException('The source duration is invalid.');
        }

        if (! is_finite($hlsDurationSeconds) || $hlsDurationSeconds <= 0) {
            throw new MediaConversionException('The HLS duration is invalid.');
        }

        $contents = is_file($playlistPath) ? file_get_contents($playlistPath) : false;

        if ($contents === false) {
            throw new MediaConversionException(
                'The final HLS video playlist is missing or unreadable.',
            );
        }

        $lines = preg_split('/\R/', trim($contents));

        if ($lines === false || end($lines) !== '#EXT-X-ENDLIST') {
            throw new MediaConversionException(
                'The final HLS video playlist has no final EXT-X-ENDLIST marker.',
            );
        }

        $segmentDurationSeconds = (float) config('media.hls.segment_duration', 6);
        $minimumRatio = (float) config('media.hls.completion_minimum_ratio', 0.99);
        $toleranceSegments = (int) config('media.hls.completion_tolerance_segments', 2);

        $minimumAcceptedDuration = max(
            $sourceDurationSeconds * $minimumRatio,
            $sourceDurationSeconds - ($segmentDurationSeconds * $toleranceSegments),
        );

        if ($hlsDurationSeconds < $minimumAcceptedDuration) {
            throw new MediaConversionException(sprintf(
                'The HLS output is truncated: %.3f seconds produced for a %.3f-second source; at least %.3f seconds were required.',
                $hlsDurationSeconds,
                $sourceDurationSeconds,
                $minimumAcceptedDuration,
            ));
        }
    }
}
```

### Ce que fait exactement ce validateur

Il applique deux contrôles indépendants.

Le premier contrôle porte sur l'état de la playlist :

```text
dernière ligne non vide = #EXT-X-ENDLIST
```

Cela empêche une playlist encore ouverte d'être considérée comme finale.

Le second contrôle porte sur sa durée. Avec une source de `7 814,634` secondes et des segments de 6 secondes :

```text
99 % de la source       = 7 736,488 s
source moins 2 segments = 7 802,634 s
minimum retenu          = 7 802,634 s
```

Une sortie de `0,250` seconde échoue donc avec un message contenant les trois valeurs utiles au diagnostic.

Le validateur ne relance pas `ffprobe`. Il reçoit uniquement les deux mesures et applique la politique métier. Il peut ainsi être testé sans encoder de vidéo.

## 5. Conserver un `HlsConverter` simple

`HlsConverter` ne doit pas connaître la durée source et ne doit pas analyser stdout. Sa fonction reste d'exécuter FFmpeg et de contrôler son code de sortie.

Le contenu attendu de `app/Services/Media/HlsConverter.php` est :

```php
<?php

namespace App\Services\Media;

use App\Exceptions\MediaConversionException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

final class HlsConverter
{
    /**
     * @param list<string> $command
     * @param callable(): void $onProgress
     */
    public function convert(array $command, callable $onProgress): void
    {
        Log::channel('my_debug')->debug('convert', ['called']);

        $process = new Process($command);
        $process->setTimeout(null);
        $lastCheck = 0.0;

        $exitCode = $process->run(
            function () use ($onProgress, &$lastCheck): void {
                $now = microtime(true);

                if ($now - $lastCheck >= 0.5) {
                    $onProgress();
                    $lastCheck = $now;
                }
            },
        );

        $onProgress();

        if ($exitCode !== 0) {
            $error = trim($process->getErrorOutput());

            throw new MediaConversionException(
                'FFmpeg a échoué : '.mb_substr(
                    $error !== '' ? $error : 'erreur inconnue',
                    0,
                    2000,
                ),
            );
        }
    }
}
```

La callback conserve son utilité : elle appelle périodiquement `$publishWhenReady()` pendant l'encodage. Son contenu n'est pas utilisé pour la validation finale.

Si `HlsConverter.php` contient déjà les variables `$progressBuffer`, `$lastOutputTimeMicroseconds` ou `$reportedEnd`, elles doivent disparaître avec cette solution.

Un éventuel fichier expérimental `HlsConverter2.php` n'est utilisé par aucune classe de cette architecture. S'il a été créé uniquement pour tester l'analyse de `out_time_us`, il peut être supprimé au moment d'appliquer cette solution.

## 6. Intégrer la validation dans `ConvertMovie`

### Ajouter les imports

Dans `app/Jobs/ConvertMovie.php`, ajouter :

```php
use App\Services\Media\HlsCompletionValidator;
use App\Services\Media\MovieInput;
```

### Injecter le validateur

Ajouter `HlsCompletionValidator` dans les paramètres de `handle()`, juste après `HlsReadinessChecker` :

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

Laravel injecte automatiquement cette classe concrète lorsque le worker exécute le job. Aucun binding n'est nécessaire dans un service provider.

### Conserver le résultat du premier ffprobe

Remplacer :

```php
$tracksSelected = $trackSelector->select($probe->probe($fileOrStream));
```

par :

```php
$sourceMediaInfo = $probe->probe($fileOrStream);
$tracksSelected = $trackSelector->select($sourceMediaInfo);
```

`$sourceMediaInfo` contient maintenant les pistes nécessaires à la conversion et la durée qui servira de référence à la fin.

### Garder l'appel actuel de `HlsConverter`

L'appel reste limité à la commande et à la callback de publication :

```php
$hlsConverter->convert(
    $hlsCommandBuilder->build(
        $fileOrStream,
        $outputDirectory,
        $tracksSelected,
    ),
    $publishWhenReady,
);
```

La durée source ne doit pas être transmise à `HlsConverter`.

### Sonder et valider le HLS final

Après le dernier `$publishWhenReady()` et le contrôle de `$published`, mais avant la requête qui écrit `Converted`, ajouter :

```php
$playlistPath = $outputDirectory.DIRECTORY_SEPARATOR.'video.m3u8';

$hlsMediaInfo = $probe->probe(new MovieInput(
    location: $playlistPath,
    isStream: false,
));

$hlsCompletionValidator->assertComplete(
    playlistPath: $playlistPath,
    sourceDurationSeconds: $sourceMediaInfo->durationSeconds,
    hlsDurationSeconds: $hlsMediaInfo->durationSeconds,
);
```

La fin de la séquence doit donc ressembler à ceci :

```php
$hlsConverter->convert(
    $hlsCommandBuilder->build(
        $fileOrStream,
        $outputDirectory,
        $tracksSelected,
    ),
    $publishWhenReady,
);

$publishWhenReady();

if (! $published) {
    throw new \RuntimeException(
        'FFmpeg finished without producing avaible hls segment.',
    );
}

$playlistPath = $outputDirectory.DIRECTORY_SEPARATOR.'video.m3u8';

$hlsMediaInfo = $probe->probe(new MovieInput(
    location: $playlistPath,
    isStream: false,
));

$hlsCompletionValidator->assertComplete(
    playlistPath: $playlistPath,
    sourceDurationSeconds: $sourceMediaInfo->durationSeconds,
    hlsDurationSeconds: $hlsMediaInfo->durationSeconds,
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

L'ordre est important :

- FFmpeg doit être terminé avant de sonder la playlist finale ;
- la playlist doit avoir été publiée avant la validation ;
- la validation doit réussir avant l'écriture de `Converted`.

Si le second `ffprobe` ou le validateur lance une exception, le `catch (Throwable $exception)` déjà présent dans le job passe le film à `Failed`. Aucun second bloc de gestion d'erreur n'est nécessaire.

## 7. Adapter les appels manuels du job

Dans `tests/Feature/Movies/ConvertMovieTest.php`, ajouter l'import :

```php
use App\Services\Media\HlsCompletionValidator;
```

Le test appelle `handle()` manuellement à plusieurs endroits. Ajouter `new HlsCompletionValidator` après `new HlsReadinessChecker` dans chacun de ces appels :

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

Il ne faut pas modifier seulement le premier test : les scénarios de succès, d'échec et de chemin invalide utilisent tous cet appel manuel.

## 8. Tester la règle de complétude

Créer `tests/Unit/HlsCompletionValidatorTest.php` :

```php
<?php

use App\Exceptions\MediaConversionException;
use App\Services\Media\HlsCompletionValidator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

function writeFinalVideoPlaylist(string $contents): string
{
    Storage::disk('public')->put('validation/video.m3u8', $contents);

    return Storage::disk('public')->path('validation/video.m3u8');
}

beforeEach(function () {
    Storage::fake('public');
});

test('a complete hls duration is accepted', function () {
    $playlistPath = writeFinalVideoPlaylist(<<<'M3U8'
#EXTM3U
#EXTINF:6.000000,
video_00000.ts
#EXT-X-ENDLIST
M3U8);

    (new HlsCompletionValidator)->assertComplete(
        playlistPath: $playlistPath,
        sourceDurationSeconds: 100.0,
        hlsDurationSeconds: 99.5,
    );
})->throwsNoExceptions();

test('a severely truncated hls duration is rejected', function () {
    $playlistPath = writeFinalVideoPlaylist(<<<'M3U8'
#EXTM3U
#EXTINF:0.250267,
video_00000.ts
#EXT-X-ENDLIST
M3U8);

    expect(fn () => (new HlsCompletionValidator)->assertComplete(
        playlistPath: $playlistPath,
        sourceDurationSeconds: 7814.634,
        hlsDurationSeconds: 0.250267,
    ))->toThrow(
        MediaConversionException::class,
        'The HLS output is truncated',
    );
});

test('a playlist without an end marker is rejected', function () {
    $playlistPath = writeFinalVideoPlaylist(<<<'M3U8'
#EXTM3U
#EXTINF:6.000000,
video_00000.ts
M3U8);

    expect(fn () => (new HlsCompletionValidator)->assertComplete(
        playlistPath: $playlistPath,
        sourceDurationSeconds: 6.0,
        hlsDurationSeconds: 6.0,
    ))->toThrow(
        MediaConversionException::class,
        'The final HLS video playlist has no final EXT-X-ENDLIST marker.',
    );
});

test('a missing final playlist is rejected', function () {
    expect(fn () => (new HlsCompletionValidator)->assertComplete(
        playlistPath: Storage::disk('public')->path('missing/video.m3u8'),
        sourceDurationSeconds: 60.0,
        hlsDurationSeconds: 60.0,
    ))->toThrow(
        MediaConversionException::class,
        'The final HLS video playlist is missing or unreadable.',
    );
});
```

Ces tests ne lancent ni FFmpeg ni `ffprobe`. Ils couvrent uniquement la règle métier avec des valeurs maîtrisées.

## 9. Tester la durée retournée par `MediaProbe`

Les tests du dossier `Unit` ne démarrent pas Laravel automatiquement dans ce projet. Comme `MediaProbe` utilise `config()`, ajouter ceci à `tests/Unit/MediaProbeTest.php` :

```php
use Tests\TestCase;

uses(TestCase::class);
```

Ajouter ensuite :

```php
test('the media probe returns the media duration', function () {
    $mediaInfo = (new MediaProbe)->probe(new MovieInput(
        location: base_path('tests/Fixtures/test2.mkv'),
        isStream: false,
    ));

    expect($mediaInfo->durationSeconds)->toBeGreaterThan(0.0);
});
```

Ce test prouve que `-show_format` est présent et que `format.duration` est correctement décodé.

## 10. Vérifier l'intégration complète

Le test existant « a queued movie is claimed atomically and conversion reaches converted » utilise la fixture MKV réelle et le vrai FFmpeg. Après les changements, il vérifiera toute la chaîne :

```text
durée de la source par ffprobe
→ conversion FFmpeg
→ durée de video.m3u8 par ffprobe
→ HlsCompletionValidator
→ statut Converted
```

Dans ce test, ajouter une vérification de la durée finale rend le résultat encore plus explicite :

```php
$playlistPath = Storage::disk('public')->path(
    "movies/{$movie->id}/hls/{$movie->conversion_attempt}/video.m3u8",
);

$hlsMediaInfo = (new MediaProbe)->probe(new MovieInput(
    location: $playlistPath,
    isStream: false,
));

expect($movie->conversion_status)->toBe(ConversionStatus::Converted)
    ->and($hlsMediaInfo->durationSeconds)->toBeGreaterThan(0.0)
    ->and($movie->conversion_completed_at)->not->toBeNull();
```

Ajouter l'import suivant dans ce fichier de test :

```php
use App\Services\Media\MovieInput;
```

Le test d'échec existant continue de prouver qu'une exception survenue dans le job conduit à `ConversionStatus::Failed`.

## 11. Exécuter les vérifications

Lancer les tests directement concernés :

```bash
php artisan test --compact tests/Unit/HlsCompletionValidatorTest.php
php artisan test --compact tests/Unit/MediaProbeTest.php
php artisan test --compact tests/Unit/TrackSelectorTest.php
php artisan test --compact tests/Feature/Movies/ConvertMovieTest.php
```

Puis formater les fichiers PHP modifiés :

```bash
vendor/bin/pint --dirty --format agent
```

## Résultat attendu lors de l'incident

Pour la sortie tronquée observée, le déroulement sera :

```text
MediaProbe(source MKV)
→ 7 814,634 secondes

HlsConverter
→ FFmpeg retourne 0

MediaProbe(video.m3u8)
→ 0,250 seconde

HlsCompletionValidator
→ minimum exigé : 7 802,634 secondes
→ MediaConversionException

ConvertMovie
→ statut Failed
→ conversion_completed_at reste vide
```

Le film peut avoir été brièvement `Playable` pendant l'encodage, ce qui est le comportement voulu pour la lecture progressive. Dès que la validation finale échoue, le bloc `catch` existant le passe à `Failed`. Il n'atteint jamais `Converted`.

## Limites assumées

Cette solution certifie que la sortie HLS couvre presque toute la durée de la source. Elle ne vérifie pas l'intégrité de chaque octet de chaque segment après la conversion.

Ce choix est volontaire :

- FFmpeg vient lui-même d'écrire les segments ;
- une erreur d'écriture doit produire un code de sortie non nul ;
- `ffprobe` doit pouvoir ouvrir la playlist et ses flux ;
- le problème à corriger est une sortie temporellement tronquée, pas une suppression ultérieure de fichiers.

Si des fichiers sont supprimés ou corrompus après une conversion réussie, cela relève d'un contrôle d'intégrité du stockage distinct.

## Résumé des changements réellement nécessaires

La solution finale ajoute seulement :

- une propriété `durationSeconds` dans `MediaInfo` ;
- `-show_format` et le décodage de `format.duration` dans `MediaProbe` ;
- deux paramètres de tolérance dans `config/media.php` ;
- un petit `HlsCompletionValidator` ;
- un second appel à `MediaProbe` dans `ConvertMovie` ;
- l'appel du validateur avant `Converted`.

Elle ne nécessite aucune modification du protocole HTTP, aucun parsing de stdout et aucune modification de la commande FFmpeg.
