# Explications détaillées de l’intégration des sous-titres

Ce document reprend la logique de `ADD_SUBTITLES.md` sans la modifier. Il développe surtout les étapes 4 à 9, c’est-à-dire le passage entre les fichiers sélectionnés dans le torrent et les pistes finalement proposées dans le lecteur.

La partie consacrée aux tests n’est pas répétée ici.

## Point de départ

Les trois premières étapes de `ADD_SUBTITLES.md` ont déjà établi que :

1. `DownloadMovie` inspecte le torrent ;
2. il sélectionne la vidéo principale et tous les `.srt`/`.vtt` non vides ;
3. il envoie tous leurs index au même téléchargement torrent ;
4. il enregistre les sous-titres externes dans `movies.external_subtitles`.

Après ces étapes, un film peut par exemple contenir ceci en base de données :

```json
[
    {
        "file_index": 4,
        "file_name": "Movie.english.srt",
        "language": "eng"
    },
    {
        "file_index": 7,
        "file_name": "Movie.srt",
        "language": "und"
    }
]
```

Ces données ne sont pas encore des pistes HLS. Elles indiquent seulement :

- quel fichier demander au client torrent avec `file_index` ;
- sous quel nom il sera enregistré avec `file_name` ;
- quelle langue afficher avec `language`.

Les étapes suivantes transforment ces informations en pistes utilisables par FFmpeg et Hls.js.

## 4. Pourquoi créer une représentation commune des pistes ?

### 4.1 Le problème à résoudre

FFmpeg identifie une piste avec deux nombres :

```text
numéro de l’entrée : numéro de la piste dans cette entrée
```

Par exemple :

```text
0:0
0:1
0:2
1:0
```

Le premier nombre désigne le fichier donné avec `-i`. Le second désigne une piste à l’intérieur de ce fichier.

Prenons une commande simplifiée :

```bash
ffmpeg \
  -i Movie.mkv \
  -i Movie.english.vtt \
  -i Movie.other.vtt
```

FFmpeg numérote les entrées dans l’ordre :

```text
Entrée 0 = Movie.mkv
Entrée 1 = Movie.english.vtt
Entrée 2 = Movie.other.vtt
```

Le MKV peut lui-même contenir plusieurs pistes :

```text
0:0 = vidéo intégrée au MKV
0:1 = audio intégré au MKV
0:2 = sous-titre anglais intégré au MKV
0:3 = sous-titre français intégré au MKV
```

Chaque fichier WebVTT externe ne contient normalement qu’une seule piste :

```text
1:0 = piste de Movie.english.vtt
2:0 = piste de Movie.other.vtt
```

Une piste intégrée et une piste externe ne viennent donc pas du même endroit :

- une piste intégrée peut être `0:2` ou `0:3` ;
- une piste externe sera `1:0`, `2:0`, etc.

Le pipeline HLS ne devrait pas avoir deux logiques différentes partout. Il a seulement besoin de savoir :

- où se trouve la piste pour FFmpeg ;
- quelle est sa langue ;
- quel nom afficher ;
- si elle est intégrée ou externe.

### 4.2 Le rôle de `SubtitleTrack`

Créer `app/Data/SubtitleTrack.php` :

```php
<?php

namespace App\Data;

final readonly class SubtitleTrack
{
    public function __construct(
        public int $inputIndex,
        public int $streamIndex,
        public string $language,
        public string $title,
        public string $origin,
    ) {}

    public function sourceMap(): string
    {
        return "{$this->inputIndex}:{$this->streamIndex}";
    }

    public function hlsLanguage(): string
    {
        return match ($this->language) {
            'fra' => 'fr',
            'eng' => 'en',
            'deu' => 'de',
            'ita' => 'it',
            default => 'und',
        };
    }
}
```

Cette classe ne télécharge et ne convertit rien. C’est un petit objet de données qui décrit une piste.

Chaque propriété a un rôle précis :

| Propriété | Exemple | Rôle |
| --- | --- | --- |
| `inputIndex` | `1` | Numéro du `-i` FFmpeg contenant la piste |
| `streamIndex` | `0` | Numéro de la piste dans cette entrée |
| `language` | `eng` | Langue interne normalisée |
| `title` | `Movie.english.srt` | Nom utilisable dans le lecteur |
| `origin` | `external` | Permet d’afficher `Embedded` ou `External` |

La méthode suivante :

```php
public function sourceMap(): string
{
    return "{$this->inputIndex}:{$this->streamIndex}";
}
```

transforme les deux index en syntaxe FFmpeg :

```php
new SubtitleTrack(
    inputIndex: 0,
    streamIndex: 2,
    language: 'eng',
    title: '',
    origin: 'embedded',
);
```

donne :

```text
0:2
```

Tandis que :

```php
new SubtitleTrack(
    inputIndex: 1,
    streamIndex: 0,
    language: 'eng',
    title: 'Movie.english.srt',
    origin: 'external',
);
```

donne :

```text
1:0
```

`hlsLanguage()` effectue une autre conversion. Le backend utilise des codes sur trois lettres, tandis que le manifeste HLS doit exposer les codes compris par le lecteur :

```text
eng -> en
fra -> fr
deu -> de
ita -> it
und -> und
```

### 4.3 Pourquoi modifier `SelectedTracks` ?

Actuellement, `SelectedTracks::$subtitles` contient des `MediaStream`. Cela fonctionne uniquement parce que toutes les pistes viennent du fichier vidéo analysé par `ffprobe`.

Après cette fonctionnalité, la liste doit pouvoir contenir à la fois :

- une piste intégrée située dans l’entrée vidéo ;
- une piste externe située dans une autre entrée.

On change donc uniquement le type des éléments de la liste :

```php
/** @param list<SubtitleTrack> $subtitles */
public function __construct(
    public MediaStream $video,
    public ?MediaStream $audio,
    public array $subtitles,
) {}
```

La vidéo et l’audio restent des `MediaStream`, car ils viennent toujours de l’entrée principale. Seuls les sous-titres ont besoin de connaître leur numéro d’entrée FFmpeg.

### 4.4 Transformer les pistes intégrées dans `TrackSelector`

`MediaProbe` analyse le conteneur vidéo. Il renvoie par exemple :

```text
Stream 0 = vidéo
Stream 1 = audio
Stream 2 = sous-titre anglais
Stream 3 = sous-titre français
```

Ces pistes appartiennent toutes au premier fichier donné à FFmpeg. Leur `inputIndex` vaut donc toujours `0`.

Adapter `TrackSelector` :

```php
$embeddedSubtitles = array_map(
    fn (MediaStream $stream): SubtitleTrack => new SubtitleTrack(
        inputIndex: 0,
        streamIndex: $stream->index,
        language: $stream->language,
        title: $stream->title,
        origin: 'embedded',
    ),
    $media->subtitles(),
);

return new SelectedTracks(
    video: $videos[0],
    audio: $this->selectAudio($media->audios()),
    subtitles: $embeddedSubtitles,
);
```

Pour la piste intégrée numéro `2`, l’objet obtenu sera approximativement :

```php
new SubtitleTrack(
    inputIndex: 0,
    streamIndex: 2,
    language: 'eng',
    title: '',
    origin: 'embedded',
);
```

Sa source FFmpeg sera donc `0:2`.

Il faut retirer le filtre actuel qui ne conserve que quatre langues. Une piste textuelle intégrée dont la langue est inconnue doit être conservée avec `language: 'und'`, exactement comme un fichier externe inconnu.

À la fin de l’étape 4, les pistes intégrées ont une forme commune, mais les fichiers externes n’ont pas encore été ouverts ni ajoutés à cette liste.

## 5. Trouver physiquement chaque fichier externe

### 5.1 Un même fichier possède deux emplacements possibles

Un sous-titre externe peut être lu de deux façons selon l’état du torrent.

Pendant le téléchargement :

```text
http://client-torrent:7881/downloads/{downloadId}/files/{fileIndex}
```

Après le téléchargement :

```text
storage/app/public/movies/{movieId}/{fileName}
```

Le reste du pipeline ne doit pas avoir à connaître cette différence. C’est précisément le rôle déjà tenu par `MovieInputResolver` pour la vidéo.

### 5.2 Ajouter la résolution des sous-titres

Ajouter cette méthode à `MovieInputResolver` :

```php
/**
 * @param array{file_index: int, file_name: string, language: string} $subtitle
 */
public function resolveExternalSubtitle(Movie $movie, array $subtitle): MovieInput
{
    $fileName = $subtitle['file_name'];

    if (
        $fileName === ''
        || basename($fileName) !== $fileName
        || str_contains($fileName, '\\')
    ) {
        throw new \RuntimeException('Invalid external subtitle file name.');
    }

    if ($movie->download_status === DownloadStatus::Downloading && $movie->download_id !== null) {
        return new MovieInput(
            location: $this->torrentClient->fileUrl(
                $movie->download_id,
                $subtitle['file_index'],
            ),
            isStream: true,
        );
    }

    return new MovieInput(
        location: Storage::disk('public')->path(
            "movies/{$movie->id}/{$fileName}",
        ),
        isStream: false,
    );
}
```

### 5.3 Ce que contient `MovieInput`

`MovieInput` contient seulement :

- `location` : une URL ou un chemin local ;
- `isStream` : indique si les options de reconnexion HTTP sont nécessaires.

Exemple pendant le torrent :

```php
new MovieInput(
    location: 'http://client-torrent:7881/downloads/job-1/files/4',
    isStream: true,
);
```

Exemple une fois le fichier téléchargé :

```php
new MovieInput(
    location: '/var/www/html/storage/app/public/movies/42/Movie.english.srt',
    isStream: false,
);
```

Grâce à cette abstraction, le service suivant peut employer le même code dans les deux situations.

### 5.4 Pourquoi valider `file_name` ?

Cette vérification :

```php
basename($fileName) !== $fileName
```

refuse les noms contenant un chemin comme :

```text
../secret.srt
folder/subtitle.srt
/etc/passwd
```

La vérification du backslash refuse aussi un chemin de style Windows :

```text
..\secret.srt
```

Le client torrent est censé fournir un nom aplati et sûr, mais la valeur est conservée en base de données. Elle doit donc être validée avant d’être réutilisée pour construire un chemin local.

## 6. Préparer séparément chaque fichier externe

### 6.1 Pourquoi effectuer une préparation ?

On pourrait donner directement tous les `.srt` et `.vtt` à la grande commande de conversion du film. Cette solution possède cependant un défaut : la commande principale utilise `-xerror`.

Si un seul fichier externe est corrompu, FFmpeg pourrait arrêter toute la conversion. La vidéo deviendrait indisponible à cause d’une piste facultative.

La logique retenue est donc :

```text
SRT/VTT externe
      │
      ▼
petite conversion isolée
      │
      ├── succès -> WebVTT local ajouté à la conversion principale
      └── échec  -> piste ignorée, film conservé
```

Cette préparation apporte trois choses :

- tous les fichiers externes deviennent du WebVTT homogène ;
- un fichier invalide est éliminé avant la conversion du film ;
- les cues situés après la fin du film sont coupés avec `-t`.

### 6.2 Le service `ExternalSubtitlePreparer`

Créer `app/Services/Media/ExternalSubtitlePreparer.php` :

```php
<?php

namespace App\Services\Media;

use Symfony\Component\Process\Process;

final class ExternalSubtitlePreparer
{
    public function prepare(
        MovieInput $input,
        string $outputPath,
        float $videoDurationSeconds,
    ): bool {
        $process = new Process([
            (string) config('media.ffmpeg_binary'),
            '-hide_banner',
            '-y',
            ...$input->inputOptions(),
            '-i',
            $input->location,
            '-map',
            '0:0',
            '-c:s',
            'webvtt',
            '-t',
            (string) $videoDurationSeconds,
            $outputPath,
        ]);

        $process->setTimeout($input->isStream ? 300 : 60);
        $process->run();

        return $process->isSuccessful()
            && is_file($outputPath)
            && filesize($outputPath) > 0;
    }
}
```

### 6.3 Lecture détaillée de la commande

```php
(string) config('media.ffmpeg_binary'),
```

utilise le binaire FFmpeg configuré par l’application.

```php
'-hide_banner',
'-y',
```

masque l’en-tête informatif et autorise le remplacement du fichier de sortie d’une tentative.

```php
...$input->inputOptions(),
'-i',
$input->location,
```

ouvre la source. Pour un fichier local, `inputOptions()` est vide. Pour une URL du client torrent, il ajoute les délais et options de reconnexion avant `-i`.

```php
'-map',
'0:0',
```

sélectionne la première et unique piste du fichier externe. Dans cette petite commande, le SRT/VTT est l’entrée `0`.

```php
'-c:s',
'webvtt',
```

convertit la piste en WebVTT, même si la source est un SRT.

```php
'-t',
(string) $videoDurationSeconds,
```

interdit à la sortie de dépasser la durée de la vidéo principale.

```php
$outputPath,
```

écrit un fichier local comme :

```text
movies/42/hls/{attempt}/external_00.vtt
```

### 6.4 Pourquoi retourner un booléen ?

Le service ne lance volontairement pas une exception lorsqu’un sous-titre échoue :

```php
return $process->isSuccessful()
    && is_file($outputPath)
    && filesize($outputPath) > 0;
```

Le fichier est accepté uniquement si :

- FFmpeg s’est terminé correctement ;
- le fichier de sortie existe ;
- il n’est pas vide.

Sinon, `prepare()` renvoie `false` et `ConvertMovie` passe à la piste suivante.

### 6.5 Construire progressivement les entrées dans `ConvertMovie`

Ajouter `ExternalSubtitlePreparer` aux paramètres injectés dans `ConvertMovie::handle()`.

Après avoir analysé la vidéo :

```php
$sourceMediaInfo = $probe->probe($movieInput);
$tracksSelected = $trackSelector->select($sourceMediaInfo);
$ffmpegInputs = [$movieInput];
$subtitleTracks = $tracksSelected->subtitles;
```

À cet instant :

- `$ffmpegInputs[0]` est la vidéo ;
- `$subtitleTracks` contient seulement les pistes intégrées ;
- les pistes intégrées ont toutes `inputIndex: 0`.

On parcourt ensuite les fichiers externes persistés :

```php
foreach ($movie->external_subtitles ?? [] as $position => $subtitle) {
    $source = $movieInputResolver->resolveExternalSubtitle($movie, $subtitle);
```

`$source` représente soit l’URL torrent, soit le fichier local.

On choisit un nom temporaire déterministe :

```php
    $preparedPath = $outputDirectory.DIRECTORY_SEPARATOR.sprintf(
        'external_%02d.vtt',
        $position,
    );
```

Par exemple :

```text
external_00.vtt
external_01.vtt
external_02.vtt
```

On tente la préparation :

```php
    if (! $externalSubtitlePreparer->prepare(
        $source,
        $preparedPath,
        $sourceMediaInfo->durationSeconds,
    )) {
        Log::channel('my_debug')->warning('External subtitle ignored.', [
            'movie_id' => $movie->id,
            'file_name' => $subtitle['file_name'],
        ]);

        continue;
    }
```

En cas d’échec, `continue` passe directement au fichier externe suivant. La piste invalide n’entre ni dans `$ffmpegInputs`, ni dans `$subtitleTracks`.

En cas de succès :

```php
    $inputIndex = count($ffmpegInputs);
    $ffmpegInputs[] = new MovieInput($preparedPath, isStream: false);
```

`count($ffmpegInputs)` fournit le numéro que cette nouvelle entrée aura dans la commande principale.

Si le tableau contient seulement la vidéo :

```php
$ffmpegInputs = [
    0 => $movieInput,
];
```

alors `count($ffmpegInputs)` vaut `1`. Le premier WebVTT externe devient donc l’entrée FFmpeg `1`.

Après son ajout :

```php
$ffmpegInputs = [
    0 => $movieInput,
    1 => new MovieInput($preparedPath, false),
];
```

La piste correspondante est décrite ainsi :

```php
    $subtitleTracks[] = new SubtitleTrack(
        inputIndex: $inputIndex,
        streamIndex: 0,
        language: $subtitle['language'],
        title: $subtitle['file_name'],
        origin: 'external',
    );
}
```

`streamIndex` vaut `0`, car chaque WebVTT préparé contient une seule piste.

Supposons que le conteneur possède déjà une piste intégrée anglaise et que deux fichiers externes aient été préparés. On obtient :

```text
$ffmpegInputs
    [0] vidéo MKV
    [1] external_00.vtt
    [2] external_01.vtt

$subtitleTracks
    [0] source 0:2, eng, embedded
    [1] source 1:0, eng, external
    [2] source 2:0, und, external
```

Enfin, on reconstruit `SelectedTracks` :

```php
$tracksSelected = new SelectedTracks(
    video: $tracksSelected->video,
    audio: $tracksSelected->audio,
    subtitles: $subtitleTracks,
);
```

Cette reconstruction est nécessaire parce que `SelectedTracks` est un objet `readonly`. On ne modifie pas sa propriété `subtitles` après sa création ; on crée une nouvelle instance avec la liste finale.

## 7. Construire la grande commande FFmpeg avec plusieurs entrées

### 7.1 Changer la signature

Avant cette fonctionnalité, `HlsCommandBuilder` recevait une seule entrée :

```php
MovieInput $movieInput
```

Il doit maintenant recevoir :

```php
/**
 * @param list<MovieInput> $inputs
 * @return list<string>
 */
public function build(
    array $inputs,
    string $outputDirectory,
    SelectedTracks $tracks,
): array {
    // ...
}
```

Par convention :

- `$inputs[0]` est toujours la vidéo principale ;
- `$inputs[1]`, `$inputs[2]`, etc. sont les WebVTT externes préparés.

### 7.2 Commencer la commande

```php
$arguments = [
    (string) config('media.ffmpeg_binary'),
    '-hide_banner',
    '-y',
    '-xerror',
    '-progress',
    'pipe:1',
    '-nostats',
];
```

À ce stade, aucune entrée n’est encore ajoutée.

### 7.3 Ajouter toutes les entrées dans le bon ordre

```php
foreach ($inputs as $input) {
    array_push(
        $arguments,
        ...$input->inputOptions(),
        '-i',
        $input->location,
    );
}
```

Avec une vidéo encore téléchargée et deux WebVTT locaux, cette boucle produit conceptuellement :

```bash
ffmpeg \
  [options de reconnexion] \
  -i http://client-torrent:7881/downloads/job-1/files/10 \
  -i /storage/movies/42/hls/attempt/external_00.vtt \
  -i /storage/movies/42/hls/attempt/external_01.vtt
```

L’ordre est essentiel : il définit les numéros `0`, `1` et `2` stockés dans les `SubtitleTrack`.

Les options de reconnexion sont ajoutées juste avant l’entrée à laquelle elles s’appliquent. FFmpeg réinitialise les options d’entrée entre les différents `-i` ; elles ne doivent donc pas être placées une seule fois de manière globale.

### 7.4 Mapper la vidéo et l’audio

La vidéo principale est toujours dans l’entrée `0` :

```php
array_push($arguments, '-map', "0:{$tracks->video->index}");
```

L’audio est également dans l’entrée `0` :

```php
if ($tracks->audio !== null) {
    array_push($arguments, '-map', "0:{$tracks->audio->index}");
}
```

Cette partie reste identique à la logique actuelle.

### 7.5 Mapper toutes les pistes de sous-titres

```php
foreach ($tracks->subtitles as $position => $subtitle) {
    $prefix = sprintf('subtitle_%02d_%s', $position, $subtitle->language);

    array_push(
        $arguments,
        '-map',
        $subtitle->sourceMap(),
        '-c:s',
        'webvtt',
        '-f',
        'segment',
        '-segment_time',
        (string) $segmentDuration,
        '-segment_list',
        $outputDirectory.DIRECTORY_SEPARATOR."{$prefix}.m3u8",
        '-segment_list_type',
        'm3u8',
        '-segment_list_flags',
        '+live',
        '-segment_list_size',
        '0',
        $outputDirectory.DIRECTORY_SEPARATOR."{$prefix}_%05d.vtt",
    );
}
```

Pour chaque piste :

```php
'-map',
$subtitle->sourceMap(),
```

indique à FFmpeg la source exacte : `0:2`, `1:0`, `2:0`, etc.

```php
'-c:s',
'webvtt',
```

garantit que la sortie est du WebVTT.

```php
'-f',
'segment',
```

demande une sortie découpée en segments.

```php
'-segment_time',
(string) $segmentDuration,
```

utilise la même durée cible que les segments vidéo.

```php
'-segment_list',
$outputDirectory.DIRECTORY_SEPARATOR."{$prefix}.m3u8",
```

crée la playlist HLS propre à cette piste.

```php
$outputDirectory.DIRECTORY_SEPARATOR."{$prefix}_%05d.vtt",
```

crée ses segments WebVTT.

Avec trois pistes, les sorties ressemblent à :

```text
subtitle_00_eng.m3u8
subtitle_00_eng_00000.vtt
subtitle_00_eng_00001.vtt

subtitle_01_eng.m3u8
subtitle_01_eng_00000.vtt
subtitle_01_eng_00001.vtt

subtitle_02_und.m3u8
subtitle_02_und_00000.vtt
```

La position rend chaque nom unique. Deux pistes anglaises ne s’écrasent donc pas.

### 7.6 Lancer la conversion

Dans `ConvertMovie` :

```php
$hlsConverter->convert(
    $hlsCommandBuilder->build(
        $ffmpegInputs,
        $outputDirectory,
        $tracksSelected,
    ),
    $publishWhenReady,
);
```

Les trois arguments décrivent maintenant toute la conversion :

- `$ffmpegInputs` contient les fichiers à ouvrir ;
- `$outputDirectory` indique où écrire le HLS ;
- `$tracksSelected` indique quelles pistes lire dans ces entrées.

## 8. Publier et nommer toutes les pistes dans le manifeste HLS

### 8.1 Différence entre les playlists

La conversion produit plusieurs playlists :

```text
video.m3u8                 playlist des segments vidéo/audio
subtitle_00_eng.m3u8       playlist de la première piste
subtitle_01_eng.m3u8       playlist de la deuxième piste
subtitle_02_und.m3u8       playlist de la troisième piste
index.m3u8                 manifeste principal lu par le lecteur
```

Le lecteur reçoit uniquement `index.m3u8`. Celui-ci lui indique où trouver la vidéo et chaque piste de sous-titres.

### 8.2 `HlsReadinessChecker`

`HlsReadinessChecker` vérifie déjà que :

- `video.m3u8` référence au moins un segment vidéo existant ;
- chaque playlist de sous-titres attendue référence au moins un segment VTT existant.

Sa convention actuelle est :

```php
sprintf('subtitle_%02d_%s.m3u8', $position, $subtitle->language);
```

Elle est identique à celle de `HlsCommandBuilder`. La logique peut donc rester la même. Seul le type PHP de `$subtitle` devient `SubtitleTrack`.

Le manifeste principal n’est publié que lorsque les fichiers auxquels il fait référence existent réellement.

### 8.3 Construire un nom compréhensible

Adapter `HlsMasterPlaylistBuilder` :

```php
private function displayName(SubtitleTrack $subtitle, int $position): string
{
    $number = $position + 1;

    if ($subtitle->origin === 'external') {
        return "{$subtitle->title} — External {$number}";
    }

    $language = match ($subtitle->language) {
        'fra' => 'Français',
        'eng' => 'English',
        'deu' => 'Deutsch',
        'ita' => 'Italiano',
        default => 'Other',
    };

    $title = trim($subtitle->title);

    return $title !== ''
        ? "{$title} — Embedded {$number}"
        : "{$language} — Embedded {$number}";
}
```

Pour une piste externe, `title` contient le nom original :

```text
Movie.english.srt — External 2
```

Pour une piste intégrée avec un titre fourni par le conteneur :

```text
English SDH — Embedded 1
```

Pour une piste intégrée sans titre :

```text
English — Embedded 1
Other — Embedded 3
```

Le numéro est la position de la piste dans la liste finale, augmentée de un pour être lisible par un humain.

### 8.4 Ajouter chaque piste au manifeste

La boucle existante de `HlsMasterPlaylistBuilder` utilise les informations de `SubtitleTrack` :

```php
foreach ($tracks->subtitles as $position => $subtitle) {
    $prefix = sprintf('subtitle_%02d_%s', $position, $subtitle->language);
    $name = $this->attribute($this->displayName($subtitle, $position));
    $language = $this->attribute($subtitle->hlsLanguage());

    $lines[] = sprintf(
        '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="%s",LANGUAGE="%s",AUTOSELECT=YES,DEFAULT=NO,URI="%s.m3u8"',
        $name,
        $language,
        $prefix,
    );
}
```

Le résultat peut contenir :

```m3u8
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="English — Embedded 1",LANGUAGE="en",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_00_eng.m3u8"
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Movie.english.srt — External 2",LANGUAGE="en",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_01_eng.m3u8"
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Movie.srt — External 3",LANGUAGE="und",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_02_und.m3u8"
```

Chaque ligne communique au lecteur :

- `NAME` : texte présenté à l’utilisateur ;
- `LANGUAGE` : langue utilisée pour la présélection ;
- `URI` : playlist contenant les segments de cette piste ;
- `DEFAULT=NO` : le manifeste ne force aucune piste particulière ;
- `AUTOSELECT=YES` : le lecteur est autorisé à choisir selon les préférences.

Les deux pistes anglaises sont volontairement conservées. Elles ont le même `LANGUAGE`, mais des `NAME` et des `URI` différents.

## 9. Choisir automatiquement une piste sans empêcher le choix manuel

### 9.1 Ce que reçoit Hls.js

Quand Hls.js charge `index.m3u8`, il découvre toutes les lignes `EXT-X-MEDIA`. Il remplit alors :

```ts
hls.subtitleTracks
```

Cette liste contient notamment le nom, la langue et l’URL de chaque piste. Hls.js crée ensuite les pistes de texte associées à l’élément `<video>`. Le contrôle du lecteur permet à l’utilisateur de changer de piste ou de désactiver les sous-titres.

L’application ne filtre aucune piste à ce stade.

### 9.2 Convertir la préférence de l’utilisateur

La préférence utilisateur est stockée sous une forme comme `english` ou `french`, tandis que HLS expose `en` ou `fr`.

Conserver la table actuelle :

```ts
const languages: Record<string, string> = {
    english: 'en',
    french: 'fr',
    german: 'de',
    italian: 'it',
};
```

Exemple :

```text
preferredlanguage = french
languages[preferredlanguage] = fr
```

### 9.3 Attendre que les pistes soient connues

La sélection ne doit pas être faite avant le chargement du manifeste. Elle est donc placée dans l’événement :

```ts
Hls.Events.SUBTITLE_TRACKS_UPDATED
```

À ce moment, `hls.subtitleTracks` contient réellement les pistes découvertes.

### 9.4 Chercher la préférence puis l’anglais

```ts
hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, () => {
    const preferredLanguage = languages[preferredlanguage];
```

On transforme d’abord la préférence en code HLS.

```ts
    const preferredIndex = hls.subtitleTracks.findIndex(
        (track) => track.lang === preferredLanguage,
    );
```

On cherche la première piste ayant cette langue. `findIndex()` renvoie :

- `0`, `1`, `2`, etc. si une piste correspond ;
- `-1` si aucune piste ne correspond.

On prépare ensuite le repli anglais :

```ts
    const englishIndex = hls.subtitleTracks.findIndex(
        (track) => track.lang === 'en',
    );
```

Puis on choisit :

```ts
    const selectedIndex = preferredIndex !== -1
        ? preferredIndex
        : englishIndex;
```

La règle est donc :

```text
langue préférée disponible ?
    oui -> prendre sa première piste
    non -> prendre la première piste anglaise
```

Si aucune des deux n’existe, `selectedIndex` vaut `-1`.

### 9.5 Activer uniquement une piste valide

```ts
    if (selectedIndex !== -1) {
        hls.subtitleDisplay = true;
        hls.subtitleTrack = selectedIndex;
    }
});
```

`subtitleDisplay` active l’affichage des sous-titres et `subtitleTrack` indique la piste à afficher.

La présélection ne verrouille rien. Après cette sélection initiale, l’utilisateur peut toujours :

- choisir une autre piste anglaise ;
- choisir une piste d’une autre langue ;
- choisir une piste `Other` ;
- désactiver complètement les sous-titres.

### 9.6 Exemple complet

Supposons que le lecteur découvre :

```text
Index 0 : English — Embedded 1
Index 1 : Movie.english.srt — External 2
Index 2 : Français — Embedded 3
Index 3 : Movie.srt — External 4 (und)
```

Pour un utilisateur français :

```text
preferredLanguage = fr
preferredIndex = 2
selectedIndex = 2
```

La piste française est activée.

Pour un utilisateur italien :

```text
preferredLanguage = it
preferredIndex = -1
englishIndex = 0
selectedIndex = 0
```

La première piste anglaise est utilisée comme repli.

L’utilisateur italien peut ensuite sélectionner manuellement la deuxième piste anglaise ou la piste inconnue.

## Vue d’ensemble avec un exemple concret

Considérons ce torrent :

```text
Movie.mkv
Movie.english.srt
Movie.srt
```

Le MKV contient :

```text
Stream 0 : vidéo
Stream 1 : audio
Stream 2 : sous-titre anglais intégré
```

### Après l’inspection du torrent

```php
$movie->external_subtitles = [
    [
        'file_index' => 4,
        'file_name' => 'Movie.english.srt',
        'language' => 'eng',
    ],
    [
        'file_index' => 5,
        'file_name' => 'Movie.srt',
        'language' => 'und',
    ],
];
```

### Après `TrackSelector`

```text
Piste intégrée : inputIndex=0, streamIndex=2, language=eng
```

### Après `ExternalSubtitlePreparer`

```text
external_00.vtt
external_01.vtt
```

### Avant la commande HLS

```text
Entrées FFmpeg :
    0 = Movie.mkv
    1 = external_00.vtt
    2 = external_01.vtt

Pistes :
    0:2 = anglais intégré
    1:0 = anglais externe
    2:0 = langue inconnue externe
```

### Après la conversion

```text
video.m3u8
subtitle_00_eng.m3u8
subtitle_01_eng.m3u8
subtitle_02_und.m3u8
index.m3u8
```

### Dans le lecteur

```text
English — Embedded 1
Movie.english.srt — External 2
Movie.srt — External 3
```

Toutes les pistes sont conservées. La langue préférée sert seulement à choisir la piste activée au départ.

## Responsabilité de chaque classe

| Classe | Responsabilité |
| --- | --- |
| `TorrentSubtitleSelector` | Trouver les `.srt`/`.vtt` et déduire leur langue du nom |
| `DownloadMovie` | Télécharger vidéo et sous-titres dans le même torrent et persister leur description |
| `MediaProbe` | Découvrir les pistes intégrées du conteneur vidéo |
| `TrackSelector` | Transformer les pistes textuelles intégrées en `SubtitleTrack` |
| `MovieInputResolver` | Fournir une URL torrent ou un chemin local |
| `ExternalSubtitlePreparer` | Convertir isolément chaque fichier externe en WebVTT local |
| `SubtitleTrack` | Décrire uniformément une piste intégrée ou externe |
| `HlsCommandBuilder` | Construire la commande FFmpeg avec toutes les entrées et sorties |
| `HlsReadinessChecker` | Vérifier que les playlists et premiers segments existent |
| `HlsMasterPlaylistBuilder` | Déclarer toutes les pistes dans `index.m3u8` |
| `HlsPlayer` | Présélectionner une piste et laisser l’utilisateur la changer |

Cette séparation évite qu’une classe doive comprendre tout le pipeline. Chaque étape transforme une information simple avant de la transmettre à la suivante.
