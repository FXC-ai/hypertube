# Ajouter les sous-titres externes au streaming HLS

## Objectif

Ajouter au lecteur les fichiers `.srt` et `.vtt` présents dans le torrent, en plus des pistes de sous-titres déjà incorporées au conteneur vidéo.

Cette fonctionnalité répond à la section III.3 du sujet et à la section « Subtitles » de la grille d’évaluation : les sous-titres anglais disponibles doivent être téléchargés et sélectionnables, ainsi que ceux correspondant à la langue préférée de l’utilisateur lorsque cette langue diffère de celle de la vidéo.

Le périmètre volontairement retenu est simple :

- télécharger tous les `.srt` et `.vtt` non vides du torrent ;
- conserver toutes les pistes intégrées et externes, y compris les doublons ;
- ne pas chercher à associer un sous-titre à une vidéo particulière quand le torrent contient plusieurs vidéos ;
- ne pas corriger les décalages liés à des éditions différentes ;
- détecter la langue d’un fichier externe uniquement à partir de son nom ;
- classer une langue absente ou ambiguë dans `und` (« undetermined » / `Other`) ;
- laisser l’utilisateur choisir sa piste dans le lecteur ;
- utiliser sa langue préférée uniquement pour la présélection ;
- considérer la vidéo comme l’unique référence de durée et de réussite de la conversion.

## Architecture proposée

Le flux cible est le suivant :

```text
Inspection du torrent
        │
        ├── vidéo principale
        └── tous les .srt/.vtt non vides
                    │
                    ▼
Téléchargement BitTorrent unique avec plusieurs fileIndexes
                    │
                    ▼
Préparation indépendante de chaque fichier externe en WebVTT
        │                         │
        │                         └── fichier invalide : ignoré et journalisé
        ▼
Conversion HLS principale
        ├── vidéo et audio
        ├── sous-titres incorporés
        └── fichiers WebVTT externes valides
                    │
                    ▼
index.m3u8 contenant toutes les pistes
                    │
                    ▼
Hls.js présélectionne la langue préférée ou l’anglais
```

La préparation indépendante des fichiers externes est un compromis volontaire. FFmpeg peut techniquement ouvrir directement la vidéo et plusieurs fichiers externes dans une seule commande, mais une erreur d’ouverture ou de décodage sur un seul SRT ferait échouer tout le processus à cause de `-xerror`. Comme les sous-titres sont très petits, les normaliser individuellement avant la conversion principale apporte une isolation d’erreur utile avec peu de coût.

Le téléchargement reste unique : il n’y a pas un torrent séparé par sous-titre.

## 1. Persister la sélection des fichiers externes

`DownloadMovie` et `ConvertMovie` s’exécutent dans deux files différentes. La sélection doit donc être persistée entre les deux jobs.

Une colonne JSON sur `movies` suffit ; une table dédiée n’apporterait rien pour cette première version.

Créer la migration avec :

```bash
php artisan make:migration add_external_subtitles_to_movies_table --table=movies --no-interaction
```

Contenu proposé :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->json('external_subtitles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn('external_subtitles');
        });
    }
};
```

Chaque élément JSON aura cette forme :

```json
{
    "file_index": 4,
    "file_name": "Movie.english.srt",
    "language": "eng"
}
```

Modifier `Movie` :

```php
#[Fillable([
    // Attributs existants...
    'external_subtitles',
])]
```

Puis ajouter le cast :

```php
protected function casts(): array
{
    return [
        // Casts existants...
        'external_subtitles' => 'array',
    ];
}
```

Documenter également la forme pour l’analyse statique :

```php
/**
 * @property-read list<array{
 *     file_index: int,
 *     file_name: string,
 *     language: string
 * }>|null $external_subtitles
 */
```

## 2. Sélectionner les fichiers et détecter leur langue

Créer `app/Services/Torrent/TorrentSubtitleSelector.php`.

Le service :

- ne conserve que `.srt` et `.vtt` ;
- rejette les fichiers de taille nulle ;
- ne fait confiance ni à `kind` ni à `suggested`, car Laravel valide déjà les extensions lui-même pour la vidéo ;
- utilise le nom de fichier original pour détecter la langue ;
- retourne `und` si aucune langue unique n’est reconnue.

```php
<?php

namespace App\Services\Torrent;

use Illuminate\Support\Str;

final class TorrentSubtitleSelector
{
    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = ['srt', 'vtt'];

    /** @var list<string> */
    private const SUFFIX_QUALIFIERS = [
        'cc',
        'default',
        'forced',
        'full',
        'sdh',
        'signs',
    ];

    /**
     * @param  list<array{
     *     index: int,
     *     path: string,
     *     fileName: string,
     *     length: int,
     *     kind: string,
     *     suggested: bool
     * }>  $files
     * @return list<array{file_index: int, file_name: string, language: string}>
     */
    public function select(array $files): array
    {
        $subtitles = [];

        foreach ($files as $file) {
            if ($file['length'] <= 0 || ! in_array($this->extension($file['path']), self::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            $subtitles[] = [
                'file_index' => $file['index'],
                'file_name' => $file['fileName'],
                'language' => $this->detectLanguage($file['fileName']),
            ];
        }

        return $subtitles;
    }

    public function detectLanguage(string $fileName): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $normalized = Str::lower(Str::ascii($baseName));
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $detected = [];

        foreach ($tokens as $position => $token) {
            $language = $this->fullLanguageName($token);

            if ($language === null && $this->isSuffixCode($tokens, $position)) {
                $language = $this->languageCode($token);
            }

            if ($language !== null) {
                $detected[$language] = true;
            }
        }

        $languages = array_keys($detected);

        return count($languages) === 1 ? $languages[0] : 'und';
    }

    private function fullLanguageName(string $token): ?string
    {
        return match ($token) {
            'english' => 'eng',
            'francais', 'french' => 'fra',
            'italian', 'italiano' => 'ita',
            'deutsch', 'german' => 'deu',
            default => null,
        };
    }

    private function languageCode(string $token): ?string
    {
        return match ($token) {
            'en', 'eng' => 'eng',
            'fr', 'fra', 'fre' => 'fra',
            'it', 'ita' => 'ita',
            'de', 'deu', 'ger' => 'deu',
            default => null,
        };
    }

    /** @param list<string> $tokens */
    private function isSuffixCode(array $tokens, int $position): bool
    {
        foreach (array_slice($tokens, $position + 1) as $followingToken) {
            if (! in_array($followingToken, self::SUFFIX_QUALIFIERS, true)) {
                return false;
            }
        }

        return true;
    }

    private function extension(string $path): string
    {
        return Str::lower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
```

Cette règle reconnaît notamment :

```text
Movie.english.srt       -> eng
Movie.en.srt            -> eng
Movie.en.forced.srt     -> eng
Movie.français.vtt      -> fra
Movie.italiano.srt      -> ita
Movie.deutsch.vtt       -> deu
Movie.en.fr.srt         -> und
Movie.spanish.srt       -> und
Movie.srt               -> und
```

Les codes courts ne sont acceptés qu’en suffixe, éventuellement suivis d’un qualificatif connu. Cela évite de considérer le mot `de` d’un titre comme un code allemand.

## 3. Télécharger la vidéo et tous les sous-titres

Injecter `TorrentSubtitleSelector` dans `DownloadMovie::handle()` :

```php
public function handle(
    TorrentClient $torrentClient,
    TorrentVideoSelector $videoSelector,
    TorrentSubtitleSelector $subtitleSelector,
): void {
    // ...
}
```

Après l’inspection :

```php
$selectedVideo = $videoSelector->select($inspection['files']);
$externalSubtitles = $subtitleSelector->select($inspection['files']);
```

Construire la liste d’index sans doublon :

```php
$fileIndexes = array_values(array_unique([
    $selectedVideo['index'],
    ...array_column($externalSubtitles, 'file_index'),
]));

$downloadId = $torrentClient->startDownload(
    $movie->torrent_url,
    Storage::disk('public')->path("movies/{$movie->id}"),
    $fileIndexes,
    $inspection['infoHash'],
);
```

Persister la sélection avec les informations du téléchargement :

```php
Movie::query()->whereKey($movie->id)->update([
    'download_id' => $downloadId,
    'download_file_index' => $selectedVideo['index'],
    'filename' => $selectedVideo['fileName'],
    'external_subtitles' => $externalSubtitles,
]);
```

Lorsqu’un nouveau téléchargement est réclamé, remettre aussi `external_subtitles` à `null` avec les autres champs réinitialisés afin de ne pas réutiliser la sélection d’une tentative précédente.

Le client torrent accepte déjà plusieurs `fileIndexes`. Il écrit les fichiers sous leur `fileName` aplati et peut servir chaque index en HTTP pendant le téléchargement.

## 4. Représenter uniformément toutes les pistes HLS

Les pistes intégrées sont actuellement représentées directement par `MediaStream`. Une piste externe provient toutefois d’une autre entrée FFmpeg. Ajouter un petit DTO permet au reste du pipeline d’ignorer cette différence.

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

Modifier le type de `SelectedTracks::$subtitles` :

```php
/** @param list<SubtitleTrack> $subtitles */
public function __construct(
    public MediaStream $video,
    public ?MediaStream $audio,
    public array $subtitles,
) {}
```

Puis adapter `TrackSelector`. Il faut conserver toutes les pistes textuelles incorporées, y compris celles dont la langue est inconnue :

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

Il faut donc supprimer le filtre actuel limité à `fra`, `eng`, `deu` et `ita`. Une piste textuelle inconnue doit rester visible sous `Other`.

## 5. Résoudre les entrées pendant ou après le téléchargement

La vidéo utilise déjà une URL du client torrent tant que le téléchargement est en cours, puis le fichier local une fois celui-ci terminé. Appliquer exactement la même règle aux sous-titres externes.

Ajouter à `MovieInputResolver` :

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
            location: $this->torrentClient->fileUrl($movie->download_id, $subtitle['file_index']),
            isStream: true,
        );
    }

    return new MovieInput(
        location: Storage::disk('public')->path("movies/{$movie->id}/{$fileName}"),
        isStream: false,
    );
}
```

Cette validation est nécessaire même si le client torrent annonce des noms aplatis : une valeur persistée ne doit jamais pouvoir faire sortir la lecture de `movies/{id}`.

## 6. Normaliser chaque sous-titre externe sans bloquer le film

Créer `app/Services/Media/ExternalSubtitlePreparer.php`.

Le service exécute une petite conversion FFmpeg par fichier externe. Il produit un WebVTT local dans le dossier de la tentative HLS et limite sa durée à celle de la vidéo.

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

Le service retourne `false` au lieu de lever une exception : un sous-titre externe invalide est une piste inutilisable, pas un échec de conversion du film.

Dans `ConvertMovie`, après le probe de la vidéo :

```php
$sourceMediaInfo = $probe->probe($movieInput);
$tracksSelected = $trackSelector->select($sourceMediaInfo);
$ffmpegInputs = [$movieInput];
$subtitleTracks = $tracksSelected->subtitles;

foreach ($movie->external_subtitles ?? [] as $position => $subtitle) {
    $source = $movieInputResolver->resolveExternalSubtitle($movie, $subtitle);
    $preparedPath = $outputDirectory.DIRECTORY_SEPARATOR.sprintf(
        'external_%02d.vtt',
        $position,
    );

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

    $inputIndex = count($ffmpegInputs);
    $ffmpegInputs[] = new MovieInput($preparedPath, isStream: false);
    $subtitleTracks[] = new SubtitleTrack(
        inputIndex: $inputIndex,
        streamIndex: 0,
        language: $subtitle['language'],
        title: $subtitle['file_name'],
        origin: 'external',
    );
}

$tracksSelected = new SelectedTracks(
    video: $tracksSelected->video,
    audio: $tracksSelected->audio,
    subtitles: $subtitleTracks,
);
```

Ajouter `ExternalSubtitlePreparer` aux dépendances injectées dans `ConvertMovie::handle()`.

Une piste externe plus longue que la vidéo est ainsi coupée pendant sa préparation. Même sans cette coupure, elle ne prolongerait pas la lecture : `video.m3u8` reste la référence du lecteur et `HlsCompletionValidator` continue de valider uniquement la sortie vidéo.

## 7. Donner plusieurs entrées à la commande HLS

Modifier la signature de `HlsCommandBuilder::build()` :

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

Construire le début de commande puis ajouter chaque entrée. Les options HTTP doivent précéder chaque `-i`, car les options d’entrée FFmpeg ne sont pas globales :

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

foreach ($inputs as $input) {
    array_push(
        $arguments,
        ...$input->inputOptions(),
        '-i',
        $input->location,
    );
}

array_push($arguments, '-map', "0:{$tracks->video->index}");
```

Le mapping vidéo et audio continue d’utiliser l’entrée `0`. Modifier seulement le mapping des sous-titres :

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

Dans `ConvertMovie`, l’appel devient :

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

## 8. Nommer les pistes dans le manifeste principal

`HlsReadinessChecker` peut conserver sa logique et son format de préfixe, puisque `SubtitleTrack` fournit toujours `language`.

Adapter `HlsMasterPlaylistBuilder` pour utiliser `SubtitleTrack::hlsLanguage()` et afficher clairement l’origine :

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

Le manifeste pourra ainsi contenir volontairement plusieurs pistes ayant la même langue :

```m3u8
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="English — Embedded 1",LANGUAGE="en",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_00_eng.m3u8"
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Movie.english.srt — External 2",LANGUAGE="en",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_01_eng.m3u8"
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Movie.srt — External 3",LANGUAGE="und",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_02_und.m3u8"
```

Aucune déduplication ne doit être ajoutée.

## 9. Présélectionner la langue dans Hls.js

Hls.js expose automatiquement toutes les pistes déclarées dans le manifeste. Aucun composant React supplémentaire n’est nécessaire pour le choix manuel : le contrôle natif du lecteur les présente.

Conserver la correspondance existante :

```ts
const languages: Record<string, string> = {
    english: 'en',
    french: 'fr',
    german: 'de',
    italian: 'it',
};
```

Ajouter seulement un repli vers l’anglais lorsque la préférence n’est pas disponible :

```ts
hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, () => {
    const preferredLanguage = languages[preferredlanguage];
    const preferredIndex = hls.subtitleTracks.findIndex(
        (track) => track.lang === preferredLanguage,
    );
    const englishIndex = hls.subtitleTracks.findIndex(
        (track) => track.lang === 'en',
    );
    const selectedIndex = preferredIndex !== -1
        ? preferredIndex
        : englishIndex;

    if (selectedIndex !== -1) {
        hls.subtitleDisplay = true;
        hls.subtitleTrack = selectedIndex;
    }
});
```

Lorsqu’il existe plusieurs pistes dans la langue préférée, la première est présélectionnée. L’utilisateur peut ensuite en choisir une autre. La position des pistes reste déterministe : pistes intégrées dans l’ordre du conteneur, puis fichiers externes dans l’ordre du torrent.

## 10. Tests à ajouter ou modifier

Chaque partie doit être couverte par Pest.

### `tests/Unit/TorrentSubtitleSelectorTest.php`

Vérifier :

- sélection de tous les `.srt` et `.vtt` non vides ;
- rejet des autres extensions et des fichiers vides ;
- insensibilité à la casse ;
- reconnaissance des quatre langues ;
- reconnaissance de `français` après normalisation ;
- code court uniquement en suffixe ;
- `und` lorsqu’il n’y a aucun indice ;
- `und` lorsqu’il y a plusieurs langues reconnues.

Exemple :

```php
test('it detects supported subtitle languages from explicit file name tokens', function (string $fileName, string $language) {
    expect((new TorrentSubtitleSelector)->detectLanguage($fileName))->toBe($language);
})->with([
    ['Movie.english.srt', 'eng'],
    ['Movie.en.forced.srt', 'eng'],
    ['Movie.français.vtt', 'fra'],
    ['Movie.italiano.srt', 'ita'],
    ['Movie.deutsch.vtt', 'deu'],
    ['Movie.spanish.srt', 'und'],
    ['Movie.en.fr.srt', 'und'],
]);
```

### `tests/Feature/Movies/DownloadMovieTest.php`

Modifier l’assertion actuelle qui attend seulement `[2]` :

```php
Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/downloads')
    && $request['fileIndexes'] === [2, 1]
    && $request['expectedInfoHash'] === INFO_HASH);

expect($movie->refresh()->external_subtitles)->toBe([
    [
        'file_index' => 1,
        'file_name' => 'movie.srt',
        'language' => 'und',
    ],
]);
```

Ajouter également un cas avec plusieurs fichiers externes et un fichier vide.

### `tests/Feature/Movies/MovieInputTest.php`

Vérifier qu’un sous-titre :

- utilise `/downloads/{id}/files/{index}` pendant le téléchargement ;
- utilise le fichier local après le téléchargement ;
- rejette un nom absolu, `..`, `/` ou `\\`.

Adapter aussi le test de `HlsCommandBuilder` pour vérifier :

- plusieurs `-i` ;
- les options de reconnexion avant chaque entrée HTTP ;
- `0:<index>` pour une piste intégrée ;
- `1:0`, `2:0`, etc. pour les pistes externes.

### `tests/Feature/Movies/ConvertMovieTest.php`

Ajouter une fixture SRT ou VTT courte et vérifier que :

- la conversion du film arrive à `converted` ;
- une playlist `subtitle_XX_<langue>.m3u8` existe ;
- `index.m3u8` contient la piste externe ;
- les pistes intégrées restent présentes ;
- un sous-titre corrompu est ignoré sans faire échouer le film ;
- un sous-titre plus long que la vidéo ne change ni la durée de `video.m3u8`, ni le résultat de `HlsCompletionValidator`.

### Commandes de validation

Exécuter au minimum :

```bash
php artisan test --compact tests/Unit/TorrentSubtitleSelectorTest.php
php artisan test --compact tests/Unit/TrackSelectorTest.php
php artisan test --compact tests/Feature/Movies/DownloadMovieTest.php
php artisan test --compact tests/Feature/Movies/MovieInputTest.php
php artisan test --compact tests/Feature/Movies/ConvertMovieTest.php
vendor/bin/pint --dirty --format agent
npm run types:check
npm run lint:check
```

## Ordre d’implémentation conseillé

1. Ajouter la migration et le cast du modèle.
2. Ajouter `TorrentSubtitleSelector` et ses tests unitaires.
3. Étendre `DownloadMovie` et son test HTTP.
4. Ajouter `SubtitleTrack` et adapter `TrackSelector`.
5. Étendre `MovieInputResolver`.
6. Ajouter `ExternalSubtitlePreparer`.
7. Étendre `ConvertMovie` et `HlsCommandBuilder` aux entrées multiples.
8. Adapter le readiness checker et le manifeste aux nouveaux DTO.
9. Ajouter le repli anglais dans le lecteur.
10. Exécuter les tests ciblés, Pint, TypeScript et ESLint.

## Critères d’acceptation

La fonctionnalité est terminée lorsque :

- tous les `.srt` et `.vtt` non vides sont demandés au client torrent avec la vidéo ;
- les pistes textuelles incorporées restent disponibles ;
- toutes les pistes externes valides apparaissent dans le lecteur ;
- les doublons sont conservés ;
- les pistes externes affichent leur nom de fichier et leur origine ;
- une langue explicite dans le nom est normalisée en `en`, `fr`, `it` ou `de` dans HLS ;
- un nom absent, non pris en charge ou ambigu devient `Other` / `und` ;
- la préférence utilisateur présélectionne une piste correspondante, avec repli anglais ;
- l’utilisateur peut changer de piste ou désactiver les sous-titres ;
- un fichier externe invalide ne fait pas échouer la vidéo ;
- un sous-titre plus long ne prolonge pas la vidéo ;
- le film reste téléchargeable, convertible et lisible en arrière-plan conformément au sujet ;
- les tests ciblés passent sans erreur ni avertissement côté serveur ou navigateur.

## Références

- Sujet Hypertube : `docs/42/subject.md`, section III.3 « Video Part ».
- Grille d’évaluation : `docs/42/evaluation.md`, section « Subtitles ».
- Contrat du client torrent : `torrent-client/API.md`, `POST /downloads` et `GET /downloads/:id/files/:index`.
- FFmpeg : <https://ffmpeg.org/ffmpeg.html> pour les entrées multiples et `-map`.
- FFmpeg formats : <https://ffmpeg.org/ffmpeg-formats.html> pour les muxers HLS, segment et WebVTT.
