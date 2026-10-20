# Valider une conversion avec le rapport de progression de FFmpeg

## Le principe

FFmpeg sait dire deux choses avec certitude :

- si son processus s'est terminé avec une erreur technique ;
- jusqu'à quel instant il a réellement produit de la vidéo.

La première information est son code de sortie. Le projet la contrôle déjà dans `HlsConverter`.

La seconde est disponible grâce à l'option déjà présente dans `HlsCommandBuilder` :

```text
-progress pipe:1
```

Pendant la conversion, FFmpeg écrit sur sa sortie standard des blocs de ce type :

```text
frame=1248
out_time_us=52052000
out_time=00:00:52.052000
progress=continue
```

À la fin, il écrit normalement `progress=end`. `out_time_us` est la position temporelle atteinte dans la sortie, exprimée en microsecondes. Il ne s'agit ni du temps écoulé depuis le lancement du processus, ni du nombre d'octets lus.

Pour savoir si le film entier a été produit, il faut comparer la durée annoncée par `ffprobe` au dernier `out_time_us` annoncé par FFmpeg.

Dans l'incident observé :

```text
durée source       = 7 814,634 secondes
durée produite     =     0,250 seconde
code de sortie     = 0
progress final     = end
```

Le code de sortie et `progress=end` disent seulement que FFmpeg a terminé proprement ce qu'il avait reçu. La comparaison des durées révèle qu'il n'a pas reçu le film entier.

## Ce que cette solution vérifie

Une conversion sera acceptée uniquement si :

1. `ffprobe` a fourni une durée source positive ;
2. FFmpeg a retourné le code `0` ;
3. FFmpeg a envoyé `progress=end` ;
4. son dernier `out_time_us` est suffisamment proche de la durée source ;
5. `video.m3u8` se termine par `#EXT-X-ENDLIST`.

Cette solution ne reparcourt pas tous les segments `.ts`. Une erreur d'écriture rencontrée par FFmpeg doit déjà provoquer un code de sortie non nul. Le problème traité ici est une entrée HTTP tronquée que FFmpeg prend pour une fin de fichier normale.

La règle est commune aux MP4 et aux MKV. Seule la durée compte ; le conteneur d'entrée n'intervient pas dans la décision finale.

## Vue d'ensemble des modifications

| Fichier | Modification |
|---|---|
| `app/Data/MediaInfo.php` | ajouter la durée source |
| `app/Services/Media/MediaProbe.php` | demander `format.duration` à `ffprobe` |
| `config/media.php` | définir la tolérance de durée |
| `app/Services/Media/HlsConverter.php` | lire `out_time_us` et `progress=end` |
| `app/Jobs/ConvertMovie.php` | transmettre la durée et vérifier `#EXT-X-ENDLIST` |
| tests unitaires et test du job | couvrir le succès et la sortie tronquée |

## 1. Transporter la durée dans `MediaInfo`

`MediaProbe` retourne déjà un objet `MediaInfo` contenant les pistes détectées. La durée source appartient à cette même analyse et doit être conservée avec elles.

Dans `app/Data/MediaInfo.php`, remplacer uniquement le constructeur actuel :

```php
/** @param list<MediaStream> $streams */
public function __construct(
    public array $streams,
    public float $durationSeconds,
) {}
```

Conserver les méthodes `videos()`, `audios()` et `subtitles()`.

### Adapter `TrackSelectorTest`

`tests/Unit/TrackSelectorTest.php` construit directement des objets `MediaInfo`. Fournir une durée aux deux constructions existantes.

Dans `mediaInfoFixture()` :

```php
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
```

Dans le test sans piste vidéo :

```php
$media = new MediaInfo(
    streams: [new MediaStream(1, 'audio', 'aac', 'eng', '', [])],
    durationSeconds: 60.0,
);
```

La durée est arbitraire dans ces tests : `TrackSelector` ne travaille que sur les pistes. Elle doit simplement respecter le nouveau contrat de `MediaInfo`.

## 2. Demander la durée globale à `ffprobe`

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

`ffprobe` ajoutera une section semblable à celle-ci :

```json
{
    "format": {
        "duration": "7814.634000"
    }
}
```

Après le contrôle existant de `$decoded['streams']`, ajouter :

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

Remplacer ensuite le retour situé à la fin de `probe()` :

```php
return new MediaInfo(
    streams: $streams,
    durationSeconds: $durationSeconds,
);
```

Sans durée source, aucune comparaison fiable n'est possible. Une valeur de secours comme `0` désactiverait silencieusement la protection : l'absence de durée doit donc provoquer une erreur explicite.

## 3. Définir une petite tolérance

La durée de sortie peut différer légèrement de celle du conteneur à cause des horodatages et du découpage aux images clés. Il ne faut pas exiger une égalité parfaite.

Dans la section `hls` de `config/media.php`, ajouter :

```php
'hls' => [
    'segment_duration' => 6,
    'bandwidth' => 1_700_000,
    'completion_minimum_ratio' => 0.99,
    'completion_tolerance_segments' => 2,
],
```

La durée minimale acceptée sera la plus stricte des deux limites suivantes :

```php
$minimumAcceptedDuration = max(
    $sourceDurationSeconds * 0.99,
    $sourceDurationSeconds - (2 * $segmentDurationSeconds),
);
```

| Source | Limite de 99 % | Source moins 12 s | Minimum exigé |
|---:|---:|---:|---:|
| 60 s | 59,4 s | 48 s | 59,4 s |
| 7 814,634 s | 7 736,488 s | 7 802,634 s | 7 802,634 s |

Pour un film long, la perte maximale est donc d'environ deux segments. Pour une courte vidéo, la règle des 99 % empêche les 12 secondes de tolérance de devenir excessives.

## 4. Lire correctement la progression de FFmpeg

`HlsCommandBuilder` contient déjà :

```php
'-progress',
'pipe:1',
```

Il ne faut donc pas modifier cette classe.

Dans `app/Services/Media/HlsConverter.php`, remplacer la classe par cette version complète :

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
    public function convert(
        array $command,
        callable $onProgress,
        float $sourceDurationSeconds,
    ): void {
        Log::channel('my_debug')->debug('convert', ['called']);

        if (! is_finite($sourceDurationSeconds) || $sourceDurationSeconds <= 0) {
            throw new MediaConversionException('The source duration is invalid.');
        }

        $process = new Process($command);
        $process->setTimeout(null);

        $lastReadinessCheck = 0.0;
        $progressBuffer = '';
        $lastOutputTimeMicroseconds = null;
        $reportedEnd = false;

        $exitCode = $process->run(function (string $type, string $buffer) use (
            $onProgress,
            &$lastReadinessCheck,
            &$progressBuffer,
            &$lastOutputTimeMicroseconds,
            &$reportedEnd,
        ): void {
            if ($type === Process::OUT) {
                $this->consumeProgressChunk(
                    chunk: $buffer,
                    progressBuffer: $progressBuffer,
                    lastOutputTimeMicroseconds: $lastOutputTimeMicroseconds,
                    reportedEnd: $reportedEnd,
                );
            }

            $now = microtime(true);

            if ($now - $lastReadinessCheck >= 0.5) {
                $onProgress();
                $lastReadinessCheck = $now;
            }
        });

        if (trim($progressBuffer) !== '') {
            $this->consumeProgressLine(
                line: trim($progressBuffer),
                lastOutputTimeMicroseconds: $lastOutputTimeMicroseconds,
                reportedEnd: $reportedEnd,
            );
        }

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

        if (! $reportedEnd) {
            throw new MediaConversionException(
                'FFmpeg exited without reporting progress=end.',
            );
        }

        if ($lastOutputTimeMicroseconds === null) {
            throw new MediaConversionException(
                'FFmpeg exited without reporting out_time_us.',
            );
        }

        $outputDurationSeconds = $lastOutputTimeMicroseconds / 1_000_000;
        $segmentDurationSeconds = (float) config('media.hls.segment_duration', 6);
        $minimumRatio = (float) config('media.hls.completion_minimum_ratio', 0.99);
        $toleranceSegments = (int) config('media.hls.completion_tolerance_segments', 2);

        $minimumAcceptedDuration = max(
            $sourceDurationSeconds * $minimumRatio,
            $sourceDurationSeconds - ($segmentDurationSeconds * $toleranceSegments),
        );

        if ($outputDurationSeconds < $minimumAcceptedDuration) {
            throw new MediaConversionException(sprintf(
                'FFmpeg output is truncated: %.3f seconds produced for a %.3f-second source; at least %.3f seconds were required.',
                $outputDurationSeconds,
                $sourceDurationSeconds,
                $minimumAcceptedDuration,
            ));
        }
    }

    private function consumeProgressChunk(
        string $chunk,
        string &$progressBuffer,
        ?int &$lastOutputTimeMicroseconds,
        bool &$reportedEnd,
    ): void {
        $progressBuffer .= $chunk;

        while (($newlinePosition = strpos($progressBuffer, "\n")) !== false) {
            $line = substr($progressBuffer, 0, $newlinePosition);
            $progressBuffer = substr($progressBuffer, $newlinePosition + 1);

            $this->consumeProgressLine(
                line: rtrim($line, "\r"),
                lastOutputTimeMicroseconds: $lastOutputTimeMicroseconds,
                reportedEnd: $reportedEnd,
            );
        }
    }

    private function consumeProgressLine(
        string $line,
        ?int &$lastOutputTimeMicroseconds,
        bool &$reportedEnd,
    ): void {
        [$key, $value] = array_pad(explode('=', trim($line), 2), 2, null);

        if ($key === 'progress' && $value === 'end') {
            $reportedEnd = true;

            return;
        }

        if ($key !== 'out_time_us' || ! is_numeric($value)) {
            return;
        }

        $outputTimeMicroseconds = (int) $value;

        if ($outputTimeMicroseconds < 0) {
            return;
        }

        $lastOutputTimeMicroseconds = max(
            $lastOutputTimeMicroseconds ?? 0,
            $outputTimeMicroseconds,
        );
    }
}
```

### Pourquoi faut-il un tampon ?

La fonction fournie à `Process::run()` ne reçoit pas nécessairement une ligne complète. Symfony peut transmettre d'abord :

```text
out_time_
```

puis :

```text
us=250267
progress=end
```

Une analyse immédiate de chaque morceau perdrait la valeur. `consumeProgressChunk()` accumule donc le texte, traite uniquement les lignes terminées par un saut de ligne et conserve le fragment restant pour l'appel suivant.

### Pourquoi conserver la plus grande valeur ?

FFmpeg publie plusieurs rapports. Le validateur a besoin de la position la plus avancée. `max()` évite aussi qu'un horodatage légèrement inférieur reçu tardivement remplace la meilleure valeur connue.

### Pourquoi exiger `progress=end` ?

Ce marqueur confirme que le protocole de progression s'est terminé normalement. Il ne prouve pas que le film est complet : une entrée prématurément fermée peut aussi conduire à `progress=end`. C'est pour cela que la comparaison avec la durée source reste indispensable.

## 5. Fournir la durée attendue depuis `ConvertMovie`

Dans `app/Jobs/ConvertMovie.php`, conserver le résultat de `MediaProbe`.

Remplacer :

```php
$tracksSelected = $trackSelector->select($probe->probe($fileOrStream));
```

par :

```php
$mediaInfo = $probe->probe($fileOrStream);
$tracksSelected = $trackSelector->select($mediaInfo);
```

Modifier ensuite l'appel de `HlsConverter` :

```php
$hlsConverter->convert(
    command: $hlsCommandBuilder->build(
        $fileOrStream,
        $outputDirectory,
        $tracksSelected,
    ),
    onProgress: $publishWhenReady,
    sourceDurationSeconds: $mediaInfo->durationSeconds,
);
```

Le déroulement est désormais précis :

1. `MediaProbe` découvre les pistes et la durée attendue.
2. FFmpeg convertit le flux et publie sa progression.
3. `HlsConverter` contrôle le code de sortie.
4. Il exige `progress=end` et `out_time_us`.
5. Il compare la durée produite à la durée attendue.
6. Il ne rend la main à `ConvertMovie` que si tout est cohérent.

Si la sortie ne contient que `0,25` seconde, `convert()` lance `MediaConversionException`. Le job n'atteint donc jamais la requête qui écrit `ConversionStatus::Converted`.

## 6. Vérifier que la playlist HLS est fermée

Le rapport de progression décrit le travail de FFmpeg. Il reste utile de confirmer que le muxeur HLS a fermé la playlist vidéo.

Ajouter cet import dans `ConvertMovie.php` :

```php
use App\Exceptions\MediaConversionException;
```

Ajouter cette méthode privée à la fin de la classe, avant `isInsideMovieDirectory()` :

```php
private static function assertFinalPlaylistIsClosed(string $outputDirectory): void
{
    $playlistPath = $outputDirectory.DIRECTORY_SEPARATOR.'video.m3u8';
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
}
```

Après le contrôle de `$published` et avant la mise à jour vers `Converted`, appeler cette méthode :

```php
$publishWhenReady();

if (! $published) {
    throw new \RuntimeException(
        'FFmpeg finished without producing avaible hls segment.',
    );
}

self::assertFinalPlaylistIsClosed($outputDirectory);

Movie::query()
    ->whereKey($movie->id)
    ->where('conversion_attempt', $attempt)
    ->where('conversion_status', ConversionStatus::Playable->value)
    ->update([
        'conversion_status' => ConversionStatus::Converted->value,
        'conversion_completed_at' => now(),
    ]);
```

Le contrôle exige que `#EXT-X-ENDLIST` soit la dernière ligne non vide. Chercher simplement ce texte n'importe où dans le fichier serait moins sûr, car des données pourraient apparaître après le marqueur final.

## 7. Tester le rapport de progression

Créer `tests/Unit/HlsConverterTest.php` avec le contenu suivant. Ces tests n'encodent aucune vidéo : un petit processus PHP reproduit le protocole texte de `-progress pipe:1`.

```php
<?php

use App\Exceptions\MediaConversionException;
use App\Services\Media\HlsConverter;
use Tests\TestCase;

uses(TestCase::class);

function fakeProgressCommand(string $progress): array
{
    return [
        PHP_BINARY,
        '-r',
        'fwrite(STDOUT, '.var_export($progress, true).');',
    ];
}

test('a complete ffmpeg progress report is accepted', function () {
    $progressCallbackWasCalled = false;

    (new HlsConverter)->convert(
        command: fakeProgressCommand(
            "out_time_us=7814550000\nprogress=end\n",
        ),
        onProgress: function () use (&$progressCallbackWasCalled): void {
            $progressCallbackWasCalled = true;
        },
        sourceDurationSeconds: 7814.634,
    );

    expect($progressCallbackWasCalled)->toBeTrue();
});

test('a cleanly ended but truncated ffmpeg output is rejected', function () {
    expect(fn () => (new HlsConverter)->convert(
        command: fakeProgressCommand(
            "out_time_us=250267\nprogress=end\n",
        ),
        onProgress: static function (): void {},
        sourceDurationSeconds: 7814.634,
    ))->toThrow(
        MediaConversionException::class,
        'FFmpeg output is truncated: 0.250 seconds produced',
    );
});

test('a progress report without its final marker is rejected', function () {
    expect(fn () => (new HlsConverter)->convert(
        command: fakeProgressCommand("out_time_us=7814550000\n"),
        onProgress: static function (): void {},
        sourceDurationSeconds: 7814.634,
    ))->toThrow(
        MediaConversionException::class,
        'FFmpeg exited without reporting progress=end.',
    );
});

test('a progress report without an output timestamp is rejected', function () {
    expect(fn () => (new HlsConverter)->convert(
        command: fakeProgressCommand("progress=end\n"),
        onProgress: static function (): void {},
        sourceDurationSeconds: 7814.634,
    ))->toThrow(
        MediaConversionException::class,
        'FFmpeg exited without reporting out_time_us.',
    );
});
```

Ces tests vérifient la logique importante sans dépendre du réseau, du client torrent ou de la vitesse d'encodage.

## 8. Tester la durée fournie par `MediaProbe`

Les tests du dossier `Unit` n'utilisent pas automatiquement le `TestCase` Laravel dans ce projet. Or `MediaProbe` lit la configuration de l'application. Ajouter d'abord cet import et cette déclaration au début de `tests/Unit/MediaProbeTest.php` :

```php
use Tests\TestCase;

uses(TestCase::class);
```

Ajouter ensuite le test :

```php
test('the media probe returns the source duration', function () {
    $mediaInfo = (new MediaProbe)->probe(new MovieInput(
        location: base_path('tests/Fixtures/test2.mkv'),
        isStream: false,
    ));

    expect($mediaInfo->durationSeconds)->toBeGreaterThan(0.0);
});
```

Les imports de `MediaProbe` et `MovieInput` existent déjà dans ce fichier.

## 9. Le test du job reste le test d'intégration

`tests/Feature/Movies/ConvertMovieTest.php` appelle déjà la vraie chaîne :

```text
MediaProbe
→ HlsCommandBuilder
→ HlsConverter
→ FFmpeg
→ publication HLS
→ statut Converted
```

Il n'y a aucun nouveau paramètre à ajouter aux appels manuels de `ConvertMovie::handle()`, car la signature du job ne change pas. Seul l'appel interne à `HlsConverter::convert()` reçoit la durée supplémentaire.

Le test « a queued movie is claimed atomically and conversion reaches converted » vérifiera automatiquement que :

- `ffprobe` obtient une durée pour la fixture MKV ;
- le vrai FFmpeg produit `out_time_us` et `progress=end` ;
- la durée produite respecte la tolérance ;
- `video.m3u8` se termine par `#EXT-X-ENDLIST` ;
- le film atteint `ConversionStatus::Converted`.

Les tests d'échec existants doivent continuer à passer sans modification de leur intention.

## 10. Vérification finale

Exécuter uniquement les tests concernés :

```bash
php artisan test --compact tests/Unit/HlsConverterTest.php
php artisan test --compact tests/Unit/MediaProbeTest.php
php artisan test --compact tests/Unit/TrackSelectorTest.php
php artisan test --compact tests/Feature/Movies/ConvertMovieTest.php
```

Puis formater les fichiers PHP modifiés :

```bash
vendor/bin/pint --dirty --format agent
```

## Résultat obtenu

Avec cette implémentation, Laravel ne demande plus seulement à FFmpeg si son processus s'est terminé sans erreur. Il lui demande également si son rapport est arrivé jusqu'à `progress=end` et jusqu'à quelle position temporelle il a réellement produit la vidéo.

FFmpeg ne peut toujours pas savoir pourquoi son entrée s'est arrêtée. En revanche, il fournit assez d'informations pour que Laravel détecte qu'une sortie de `0,25` seconde ne peut pas représenter un film de `7 814` secondes. Cette comparaison élimine le faux statut `converted` sans analyser en détail chaque segment HLS.
