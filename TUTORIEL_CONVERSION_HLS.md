# Tutoriel complet : conversion MKV vers HLS avec plusieurs sous-titres

L’objectif est le suivant :

1. L’utilisateur ouvre la page d’un film.
2. Si aucune conversion n’existe, il voit « Watch movie ».
3. Le clic lance une conversion asynchrone.
4. FFprobe analyse le MKV.
5. Les pistes sont sélectionnées selon des règles explicites.
6. FFmpeg produit une playlist HLS vidéo/audio et une playlist WebVTT distincte pour chaque sous-titre textuel.
7. Laravel génère lui-même le manifeste maître `index.m3u8`.
8. Dès que les premières ressources sont disponibles, le film passe à `playable`.
9. Le lecteur apparaît pendant que l’encodage continue.
10. À la fin, le statut passe à `converted`.

Nous n’utiliserons pas `var_stream_map` pour les sous-titres multiples. FFmpeg sait gérer un sous-titre associé à une variante, mais gère mal plusieurs sous-titres indépendants dans ce mécanisme. Une entrée contenant uniquement `s:n` peut notamment échouer. Ce problème est documenté dans cette [discussion FFmpeg](https://ffmpeg.org/pipermail/ffmpeg-user/2020-September/050073.html).

## 1. Architecture générale

```text
app/
├── Data/{MediaInfo.php,MediaStream.php,SelectedTracks.php}
├── Enums/ConversionStatus.php
├── Exceptions/MediaConversionException.php
├── Jobs/ConvertMovie.php
├── Services/Media/{MediaProbe.php,TrackSelector.php,HlsCommandBuilder.php,HlsMasterPlaylistBuilder.php,HlsReadinessChecker.php,HlsConverter.php}
└── Http/Controllers/{MovieController.php,MovieConversionController.php}
```

- `MediaProbe` analyse le fichier.
- `TrackSelector` choisit les pistes.
- `HlsCommandBuilder` construit la commande FFmpeg.
- `HlsMasterPlaylistBuilder` génère `index.m3u8`.
- `HlsReadinessChecker` vérifie que la lecture peut commencer.
- `HlsConverter` exécute FFmpeg.
- `ConvertMovie` orchestre l’ensemble dans la queue.
- Les contrôleurs ne font que traiter les requêtes HTTP.

## 2. États de conversion

```php
enum ConversionStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Converting = 'converting';
    case Playable = 'playable';
    case Converted = 'converted';
    case Failed = 'failed';
}
```

Un booléen `converted` ne suffit pas : `playable` indique que la lecture peut commencer pendant la conversion.

## 3. Migration et modèle

```php
$table->string('conversion_status')->default(ConversionStatus::Pending->value);
$table->unsignedTinyInteger('conversion_progress')->default(0);
$table->uuid('conversion_attempt')->nullable();
$table->text('conversion_error')->nullable();
$table->timestamp('conversion_started_at')->nullable();
$table->timestamp('conversion_playable_at')->nullable();
$table->timestamp('conversion_completed_at')->nullable();
```

Dans `Movie` :

```php
protected function casts(): array
{
    return [
        'conversion_status' => ConversionStatus::class,
        'conversion_progress' => 'integer',
        'conversion_started_at' => 'datetime',
        'conversion_playable_at' => 'datetime',
        'conversion_completed_at' => 'datetime',
    ];
}

public function isPlayable(): bool
{
    return in_array($this->conversion_status, [
        ConversionStatus::Playable,
        ConversionStatus::Converted,
    ], true);
}
```

Chaque `conversion_attempt` utilise son propre dossier afin de ne jamais mélanger une tentative avec les fichiers d’une précédente conversion échouée.

## 4. Configuration

```php
return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'hls' => ['segment_duration' => 6, 'video_bandwidth' => 1_500_000],
];
```

## 5. Analyse du MKV

Une seule commande analyse toutes les pistes :

```text
ffprobe -v error -show_streams -of json fichier.mkv
```

Le JSON est décodé strictement :

```php
$result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
```

Chaque piste devient un objet `MediaStream` contenant `index`, `codec_type`, `codec_name`, `language`, `title`, `tags` et `disposition`.

Les langues sont normalisées :

```php
return match (strtolower($language ?? 'und')) {
    'fra', 'fre', 'fr' => 'fra',
    'eng', 'en' => 'eng',
    'ita', 'it' => 'ita',
    'deu', 'ger', 'de' => 'deu',
    default => 'und',
};
```

Les pistes vidéo `attached_pic` sont exclues, car il peut s’agir de couvertures. Les sous-titres textuels acceptés sont `subrip`, `srt`, `ass`, `ssa`, `webvtt` et `mov_text`. Les formats bitmap comme PGS et VobSub sont ignorés. [Documentation ffprobe](https://ffmpeg.org/ffprobe.html#Stream-specifiers-1)

## 6. Sélection des pistes

- Vidéo : erreur si aucune piste réelle, sinon première piste.
- Audio : aucune si absente ; sinon priorité `eng`, `fra`, `ita`, `deu`, puis première piste.
- Sous-titres : toutes les pistes textuelles compatibles.

La conversion étant partagée, la préférence de sous-titre de l’utilisateur ne doit pas déterminer les pistes converties. Le lecteur appliquera cette préférence.

Utiliser les index absolus :

```php
array_push($arguments, '-map', "0:{$stream->index}");
```

Cela évite tout calcul fragile de décalage.

## 7. Commande FFmpeg multi-sorties

La commande ne contient pas `var_stream_map`. Elle possède une sortie HLS vidéo/audio puis une sortie segmentée WebVTT par sous-titre :

```php
$arguments = [
    config('media.ffmpeg_binary'), '-y', '-i', $inputPath,
    '-map', "0:{$tracks->video->index}",
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
    '-pix_fmt', 'yuv420p',
    '-force_key_frames', "expr:gte(t,n_forced*{$segmentDuration})",
];

if ($tracks->audio !== null) {
    array_push($arguments, '-map', "0:{$tracks->audio->index}",
        '-c:a', 'aac', '-b:a', '128k', '-ac', '2');
}

array_push($arguments,
    '-f', 'hls', '-hls_time', $segmentDuration,
    '-hls_playlist_type', 'event', '-hls_list_size', '0',
    '-hls_flags', 'independent_segments+temp_file',
    '-hls_segment_filename', $outputDirectory.DIRECTORY_SEPARATOR.'video_%05d.ts',
    $outputDirectory.DIRECTORY_SEPARATOR.'video.m3u8');

foreach ($tracks->subtitles as $position => $subtitle) {
    $prefix = sprintf('subtitle_%02d_%s', $position, $subtitle->language);
    array_push($arguments,
        '-map', "0:{$subtitle->index}", '-c:s', 'webvtt',
        '-f', 'segment', '-segment_time', $segmentDuration,
        '-segment_list', $outputDirectory.DIRECTORY_SEPARATOR."{$prefix}.m3u8",
        '-segment_list_type', 'm3u8',
        $outputDirectory.DIRECTORY_SEPARATOR."{$prefix}_%05d.vtt");
}
```

Symfony Process reçoit directement ce tableau. Les segments WebVTT peuvent être plus longs que six secondes lorsque les cues sont espacés : le muxer coupe aux paquets de sous-titres disponibles.

## 8. Manifeste maître Laravel

FFmpeg produit `video.m3u8` et une playlist par sous-titre. Laravel écrit atomiquement `index.m3u8` :

```m3u8
#EXTM3U
#EXT-X-VERSION:3

#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Français",LANGUAGE="fr",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_00_fra.m3u8"
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="English",LANGUAGE="en",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_01_eng.m3u8"
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Deutsch",LANGUAGE="de",AUTOSELECT=YES,DEFAULT=NO,URI="subtitle_02_deu.m3u8"

#EXT-X-STREAM-INF:BANDWIDTH=1500000,SUBTITLES="subtitles"
video.m3u8
```

Écriture atomique : écrire `index.m3u8.tmp`, puis le renommer en `index.m3u8`. Les valeurs provenant des métadonnées doivent être échappées (`\\`, `"`, retours à la ligne).

## 9. Détection de l’état `playable`

Avant de publier le manifeste, vérifier :

1. `video.m3u8` existe ;
2. elle référence au moins un segment existant ;
3. chaque playlist de sous-titres annoncée existe ;
4. chacune référence au moins un fichier `.vtt` existant.

Lorsque ces conditions sont remplies, Laravel écrit `index.m3u8` puis enregistre :

```php
$movie->update([
    'conversion_status' => ConversionStatus::Playable,
    'conversion_playable_at' => now(),
]);
```

À la fin de FFmpeg :

```php
$movie->update([
    'conversion_status' => ConversionStatus::Converted,
    'conversion_progress' => 100,
    'conversion_completed_at' => now(),
]);
```

## 10. Exécution asynchrone

La conversion appartient à un Job Laravel avec un timeout illimité pour Symfony Process et une protection contre les doublons :

```php
public int $timeout = 0;

public function middleware(): array
{
    return [(new WithoutOverlapping("movie-conversion:{$this->movieId}"))
        ->dontRelease()->expireAfter(7200)];
}
```

Le Job suit :

```text
queued → converting → playable → converted
                         └──────→ failed
```

Il analyse le fichier, sélectionne les pistes, crée un dossier portant l’UUID de la tentative, lance FFmpeg, surveille la disponibilité des playlists, publie le manifeste puis enregistre le résultat. Toute exception place le film en `failed` et conserve son message dans `conversion_error`.

[Documentation Laravel sur les Jobs sans chevauchement](https://laravel.com/framework/docs/12.x/queues#preventing-job-overlaps)

## 11. Contrôleur et routes

Le lancement doit être un `POST` :

```php
Route::post('/movies/{movie}/conversion', [MovieConversionController::class, 'store'])
    ->name('movies.conversion.store');
Route::get('/movies/{movie}/conversion', [MovieConversionController::class, 'show'])
    ->name('movies.conversion.show');
```

Le contrôleur effectue une mise à jour conditionnelle de `pending` ou `failed` vers `queued`. Il ne distribue le Job que si une ligne a réellement été modifiée, empêchant deux clics simultanés de lancer deux conversions.

L’endpoint de statut retourne :

```json
{"status":"playable","progress":35,"playable":true,"error":null}
```

## 12. Distribution HLS sécurisée

Le manifeste et les playlists utilisent `no-cache`; les segments terminés utilisent un cache immuable. Les extensions ont les types suivants :

- `.m3u8` : `application/vnd.apple.mpegurl`
- `.ts` : `video/mp2t`
- `.vtt` : `text/vtt; charset=UTF-8`
- `.m4s` : `video/iso.segment`

Valider les noms demandés avec :

```php
preg_match('/\A[a-zA-Z0-9_-]+\.(?:m3u8|ts|vtt|m4s|mp4)\z/', $segment)
```

Cela empêche les traversées de répertoires. Les fichiers sont lus dans le dossier de `conversion_attempt` actif.

## 13. Interface React

- `pending` ou `failed` : bouton « Watch movie » ;
- `queued` ou `converting` : attente et polling toutes les deux ou trois secondes ;
- `playable` ou `converted` : lecteur HLS.

Quand le statut devient `playable`, le polling s’arrête et le lecteur apparaît. La langue préférée est sélectionnée côté Hls.js :

```tsx
hls.on(Hls.Events.MANIFEST_PARSED, () => {
    const index = hls.subtitleTracks.findIndex(
        (track) => track.lang === preferredSubtitleLanguage,
    );
    hls.subtitleTrack = index;
});
```

Si aucune piste ne correspond, utiliser `hls.subtitleTrack = -1`.

## 14. Tests indispensables

- JSON ffprobe valide/invalide et langues absentes ;
- absence de vidéo et exclusion de `attached_pic` ;
- sélection de la première vidéo ;
- audio absent, unique, prioritaire ou de langue inconnue ;
- normalisation `fre/fra` et `ger/deu` ;
- sous-titres textuels acceptés et bitmap ignorés ;
- deux sous-titres de même langue avec des noms distincts ;
- commande sans audio ou sans sous-titres ;
- commande avec trois sous-titres et absence de `var_stream_map` ;
- manifeste maître avec une déclaration par piste ;
- écriture atomique du manifeste ;
- transitions jusqu’à `playable`, `converted` ou `failed` ;
- un seul Job malgré deux requêtes ;
- refus d’un nom de segment dangereux ;
- test d’intégration réel avec un MKV contenant trois sous-titres.

## 15. Décisions finales

1. Un seul appel ffprobe analyse toutes les pistes.
2. Les résultats sont représentés par des objets typés.
3. La sélection des pistes est indépendante de FFmpeg.
4. Les index absolus `0:n` sont utilisés.
5. Les sous-titres bitmap sont ignorés.
6. Toutes les pistes textuelles sont converties.
7. Un seul processus FFmpeg possède plusieurs sorties.
8. `var_stream_map` n’est pas utilisé.
9. FFmpeg produit les playlists vidéo et WebVTT.
10. Laravel génère `index.m3u8`.
11. Le manifeste n’est publié que lorsque ses ressources existent.
12. `playable` est distinct de `converted`.
13. La préférence linguistique est appliquée dans le lecteur.
14. Le Job est protégé contre les exécutions concurrentes.
15. Chaque tentative utilise un dossier distinct.

Cette architecture contourne explicitement la faiblesse de FFmpeg avec plusieurs sous-titres HLS dans `var_stream_map`, tout en conservant une seule analyse du fichier et un seul processus de conversion.

# Annexe — code complet fichier par fichier

Les blocs suivants indiquent explicitement le fichier de destination de chaque classe. Ils constituent une proposition d’implémentation et ne sont pas appliqués automatiquement au projet.

## `app/Enums/ConversionStatus.php`

```php
<?php

namespace App\Enums;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Converting = 'converting';
    case Playable = 'playable';
    case Converted = 'converted';
    case Failed = 'failed';
}
```

## `database/migrations/xxxx_xx_xx_xxxxxx_add_conversion_fields_to_movies_table.php`

```php
<?php

use App\Enums\ConversionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->string('conversion_status')->default(ConversionStatus::Pending->value);
            $table->uuid('conversion_attempt')->nullable();
            $table->text('conversion_error')->nullable();
            $table->timestamp('conversion_started_at')->nullable();
            $table->timestamp('conversion_playable_at')->nullable();
            $table->timestamp('conversion_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->dropColumn([
                'conversion_status',
                'conversion_attempt',
                'conversion_error',
                'conversion_started_at',
                'conversion_playable_at',
                'conversion_completed_at',
            ]);
        });
    }
};
```

## `app/Models/Movie.php`

```php
<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'title', 'filepath', 'filename', 'conversion_status', 'conversion_attempt',
    'conversion_error', 'conversion_started_at', 'conversion_playable_at',
    'conversion_completed_at',
])]
class Movie extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'conversion_status' => ConversionStatus::class,
            'conversion_started_at' => 'datetime',
            'conversion_playable_at' => 'datetime',
            'conversion_completed_at' => 'datetime',
        ];
    }

    public function isPlayable(): bool
    {
        return in_array($this->conversion_status, [
            ConversionStatus::Playable,
            ConversionStatus::Converted,
        ], true);
    }
}
```

## `config/media.php`

```php
<?php

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'hls' => [
        'segment_duration' => 6,
        'bandwidth' => 1_500_000,
    ],
];
```

Variables correspondantes dans `.env` :

```dotenv
FFMPEG_BINARY=C:\ffmpeg\bin\ffmpeg.exe
FFPROBE_BINARY=C:\ffmpeg\bin\ffprobe.exe
```

## `app/Exceptions/MediaConversionException.php`

```php
<?php

namespace App\Exceptions;

use RuntimeException;

final class MediaConversionException extends RuntimeException
{
}
```

## `app/Data/MediaStream.php`

```php
<?php

namespace App\Data;

final readonly class MediaStream
{
    /**
     * @param array<string, mixed> $tags
     * @param array<string, mixed> $disposition
     */
    public function __construct(
        public int $index,
        public string $type,
        public string $codec,
        public string $language,
        public string $title,
        public array $tags,
        public array $disposition,
    ) {}

    public function isAttachedPicture(): bool
    {
        return (bool) ($this->disposition['attached_pic'] ?? false);
    }

    public function isTextSubtitle(): bool
    {
        return in_array($this->codec, [
            'subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text',
        ], true);
    }
}
```

## `app/Data/MediaInfo.php`

```php
<?php

namespace App\Data;

final readonly class MediaInfo
{
    /** @param list<MediaStream> $streams */
    public function __construct(public array $streams) {}

    /** @return list<MediaStream> */
    public function videos(): array
    {
        return array_values(array_filter($this->streams,
            fn (MediaStream $stream): bool => $stream->type === 'video'
                && ! $stream->isAttachedPicture()));
    }

    /** @return list<MediaStream> */
    public function audios(): array
    {
        return array_values(array_filter($this->streams,
            fn (MediaStream $stream): bool => $stream->type === 'audio'));
    }

    /** @return list<MediaStream> */
    public function subtitles(): array
    {
        return array_values(array_filter($this->streams,
            fn (MediaStream $stream): bool => $stream->type === 'subtitle'
                && $stream->isTextSubtitle()));
    }
}
```

## `app/Data/SelectedTracks.php`

```php
<?php

namespace App\Data;

final readonly class SelectedTracks
{
    /** @param list<MediaStream> $subtitles */
    public function __construct(
        public MediaStream $video,
        public ?MediaStream $audio,
        public array $subtitles,
    ) {}
}
```

## `app/Services/Media/MediaProbe.php`

```php
<?php

namespace App\Services\Media;

use App\Data\MediaInfo;
use App\Data\MediaStream;
use App\Exceptions\MediaConversionException;
use JsonException;
use Symfony\Component\Process\Process;

final class MediaProbe
{
    public function probe(string $inputPath): MediaInfo
    {
        if (! is_file($inputPath)) {
            throw new MediaConversionException("Le fichier source n'existe pas : {$inputPath}");
        }

        $process = new Process([
            config('media.ffprobe_binary'), '-v', 'error', '-show_streams',
            '-of', 'json', $inputPath,
        ]);
        $process->mustRun();

        try {
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MediaConversionException('FFprobe a retourné un JSON invalide.', previous: $exception);
        }

        $rawStreams = $result['streams'] ?? null;
        if (! is_array($rawStreams)) {
            throw new MediaConversionException('FFprobe n’a retourné aucune liste de pistes.');
        }

        return new MediaInfo(array_map(
            fn (array $stream): MediaStream => $this->createStream($stream),
            $rawStreams,
        ));
    }

    /** @param array<string, mixed> $stream */
    private function createStream(array $stream): MediaStream
    {
        $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];
        $disposition = is_array($stream['disposition'] ?? null) ? $stream['disposition'] : [];

        return new MediaStream(
            index: (int) $stream['index'],
            type: (string) ($stream['codec_type'] ?? 'unknown'),
            codec: (string) ($stream['codec_name'] ?? 'unknown'),
            language: $this->normalizeLanguage(isset($tags['language']) ? (string) $tags['language'] : null),
            title: (string) ($tags['title'] ?? ''),
            tags: $tags,
            disposition: $disposition,
        );
    }

    private function normalizeLanguage(?string $language): string
    {
        return match (strtolower($language ?? 'und')) {
            'fra', 'fre', 'fr' => 'fra',
            'eng', 'en' => 'eng',
            'ita', 'it' => 'ita',
            'deu', 'ger', 'de' => 'deu',
            default => 'und',
        };
    }
}
```

## `app/Services/Media/TrackSelector.php`

```php
<?php

namespace App\Services\Media;

use App\Data\MediaInfo;
use App\Data\MediaStream;
use App\Data\SelectedTracks;
use App\Exceptions\MediaConversionException;

final class TrackSelector
{
    public function select(MediaInfo $mediaInfo): SelectedTracks
    {
        $video = $mediaInfo->videos()[0] ?? null;
        if ($video === null) {
            throw new MediaConversionException('Le fichier ne contient aucune piste vidéo exploitable.');
        }

        return new SelectedTracks(
            video: $video,
            audio: $this->selectAudio($mediaInfo->audios()),
            subtitles: $mediaInfo->subtitles(),
        );
    }

    /** @param list<MediaStream> $audioStreams */
    private function selectAudio(array $audioStreams): ?MediaStream
    {
        if ($audioStreams === []) {
            return null;
        }

        foreach (['eng', 'fra', 'ita', 'deu'] as $preferredLanguage) {
            foreach ($audioStreams as $audioStream) {
                if ($audioStream->language === $preferredLanguage) {
                    return $audioStream;
                }
            }
        }

        return $audioStreams[0];
    }
}
```

## `app/Services/Media/HlsFileNamer.php`

```php
<?php

namespace App\Services\Media;

use App\Data\MediaStream;

final class HlsFileNamer
{
    public function subtitlePrefix(MediaStream $subtitle, int $position): string
    {
        return sprintf('subtitle_%02d_%s', $position, $subtitle->language);
    }

    public function subtitlePlaylist(MediaStream $subtitle, int $position): string
    {
        return $this->subtitlePrefix($subtitle, $position).'.m3u8';
    }

    public function subtitleSegmentPattern(MediaStream $subtitle, int $position): string
    {
        return $this->subtitlePrefix($subtitle, $position).'_%05d.vtt';
    }
}
```

## `app/Services/Media/HlsCommandBuilder.php`

```php
<?php

namespace App\Services\Media;

use App\Data\MediaStream;
use App\Data\SelectedTracks;

final readonly class HlsCommandBuilder
{
    public function __construct(private HlsFileNamer $fileNamer) {}

    /** @return list<string> */
    public function build(string $inputPath, string $outputDirectory, SelectedTracks $tracks): array
    {
        $duration = (string) config('media.hls.segment_duration', 6);
        $arguments = [
            config('media.ffmpeg_binary'), '-y', '-i', $inputPath,
            '-map', "0:{$tracks->video->index}",
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-pix_fmt', 'yuv420p', '-force_key_frames', "expr:gte(t,n_forced*{$duration})",
        ];

        if ($tracks->audio !== null) {
            array_push($arguments, '-map', "0:{$tracks->audio->index}",
                '-c:a', 'aac', '-b:a', '128k', '-ac', '2');
        }

        array_push($arguments,
            '-f', 'hls', '-hls_time', $duration,
            '-hls_playlist_type', 'event', '-hls_list_size', '0',
            '-hls_flags', 'independent_segments+temp_file',
            '-hls_segment_filename', $outputDirectory.DIRECTORY_SEPARATOR.'video_%05d.ts',
            $outputDirectory.DIRECTORY_SEPARATOR.'video.m3u8');

        foreach ($tracks->subtitles as $position => $subtitle) {
            $this->appendSubtitle($arguments, $outputDirectory, $subtitle, $position, $duration);
        }

        return $arguments;
    }

    /** @param list<string> $arguments */
    private function appendSubtitle(array &$arguments, string $directory, MediaStream $subtitle, int $position, string $duration): void
    {
        array_push($arguments,
            '-map', "0:{$subtitle->index}", '-c:s', 'webvtt',
            '-f', 'segment', '-segment_time', $duration,
            '-segment_list', $directory.DIRECTORY_SEPARATOR.$this->fileNamer->subtitlePlaylist($subtitle, $position),
            '-segment_list_type', 'm3u8',
            $directory.DIRECTORY_SEPARATOR.$this->fileNamer->subtitleSegmentPattern($subtitle, $position));
    }
}
```

## `app/Services/Media/HlsMasterPlaylistBuilder.php`

```php
<?php

namespace App\Services\Media;

use App\Data\MediaStream;
use App\Data\SelectedTracks;
use App\Exceptions\MediaConversionException;

final readonly class HlsMasterPlaylistBuilder
{
    public function __construct(private HlsFileNamer $fileNamer) {}

    public function publish(string $directory, SelectedTracks $tracks): void
    {
        $temporary = $directory.DIRECTORY_SEPARATOR.'index.m3u8.tmp';
        $final = $directory.DIRECTORY_SEPARATOR.'index.m3u8';
        if (file_put_contents($temporary, $this->build($tracks), LOCK_EX) === false) {
            throw new MediaConversionException('Impossible d’écrire le manifeste HLS temporaire.');
        }
        if (! rename($temporary, $final)) {
            throw new MediaConversionException('Impossible de publier le manifeste HLS.');
        }
    }

    public function build(SelectedTracks $tracks): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3', ''];
        foreach ($tracks->subtitles as $position => $subtitle) {
            $lines[] = $this->subtitleDeclaration($subtitle, $position);
        }
        if ($tracks->subtitles !== []) {
            $lines[] = '';
        }
        $stream = '#EXT-X-STREAM-INF:BANDWIDTH='.(int) config('media.hls.bandwidth', 1_500_000);
        if ($tracks->subtitles !== []) {
            $stream .= ',SUBTITLES="subtitles"';
        }
        array_push($lines, $stream, 'video.m3u8', '');
        return implode("\n", $lines);
    }

    private function subtitleDeclaration(MediaStream $subtitle, int $position): string
    {
        $name = $subtitle->title !== '' ? $subtitle->title : $this->languageName($subtitle->language);
        return sprintf('#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="%s",LANGUAGE="%s",AUTOSELECT=YES,DEFAULT=NO,URI="%s"',
            $this->escape($name), $this->hlsLanguage($subtitle->language),
            $this->fileNamer->subtitlePlaylist($subtitle, $position));
    }

    private function languageName(string $language): string
    {
        return match ($language) {
            'fra' => 'Français', 'eng' => 'English', 'ita' => 'Italiano',
            'deu' => 'Deutsch', default => 'Unknown',
        };
    }

    private function hlsLanguage(string $language): string
    {
        return match ($language) {
            'fra' => 'fr', 'eng' => 'en', 'ita' => 'it', 'deu' => 'de', default => 'und',
        };
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ' '], $value);
    }
}
```

## `app/Services/Media/HlsReadinessChecker.php`

```php
<?php

namespace App\Services\Media;

use App\Data\SelectedTracks;

final readonly class HlsReadinessChecker
{
    public function __construct(private HlsFileNamer $fileNamer) {}

    public function isReady(string $directory, SelectedTracks $tracks): bool
    {
        if (! $this->playlistHasSegment($directory, 'video.m3u8')) {
            return false;
        }
        foreach ($tracks->subtitles as $position => $subtitle) {
            if (! $this->playlistHasSegment($directory, $this->fileNamer->subtitlePlaylist($subtitle, $position))) {
                return false;
            }
        }
        return true;
    }

    private function playlistHasSegment(string $directory, string $filename): bool
    {
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        if (! is_file($path) || ($contents = file_get_contents($path)) === false) {
            return false;
        }
        if (preg_match('/^(?!#)(.+\.(?:ts|vtt|m4s))\s*$/m', $contents, $matches) !== 1) {
            return false;
        }
        return is_file($directory.DIRECTORY_SEPARATOR.basename(trim($matches[1])));
    }
}
```

## `app/Services/Media/HlsConverter.php`

```php
<?php

namespace App\Services\Media;

use App\Exceptions\MediaConversionException;
use Symfony\Component\Process\Process;

final class HlsConverter
{
    /** @param list<string> $arguments @param callable(): void $onOutput */
    public function convert(array $arguments, callable $onOutput): void
    {
        $process = new Process($arguments);
        $process->setTimeout(null);
        $process->run(function (string $type, string $output) use ($onOutput): void {
            $onOutput();
        });
        if (! $process->isSuccessful()) {
            throw new MediaConversionException(trim($process->getErrorOutput()) ?: 'La conversion FFmpeg a échoué.');
        }
    }
}
```

## `app/Jobs/ConvertMovie.php`

```php
<?php

namespace App\Jobs;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use App\Services\Media\{HlsCommandBuilder, HlsConverter, HlsMasterPlaylistBuilder, HlsReadinessChecker, MediaProbe, TrackSelector};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ConvertMovie implements ShouldQueue
{
    use Queueable;
    public int $timeout = 0;

    public function __construct(public int $movieId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("movie-conversion:{$this->movieId}"))->dontRelease()->expireAfter(7200)];
    }

    public function handle(MediaProbe $probe, TrackSelector $selector, HlsCommandBuilder $commands,
        HlsMasterPlaylistBuilder $master, HlsReadinessChecker $readiness, HlsConverter $converter): void
    {
        $movie = Movie::query()->findOrFail($this->movieId);
        $attempt = (string) Str::uuid();
        $movie->update([
            'conversion_status' => ConversionStatus::Converting,
            'conversion_attempt' => $attempt,
            'conversion_error' => null,
            'conversion_started_at' => now(),
            'conversion_playable_at' => null,
            'conversion_completed_at' => null,
        ]);

        $input = Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}");
        $directory = Storage::disk('public')->path("movies/{$movie->id}/hls/{$attempt}");

        try {
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Impossible de créer le dossier HLS.');
            }
            $tracks = $selector->select($probe->probe($input));
            $published = false;
            $converter->convert($commands->build($input, $directory, $tracks),
                function () use ($movie, $tracks, $directory, $readiness, $master, &$published): void {
                    if (! $published && $readiness->isReady($directory, $tracks)) {
                        $master->publish($directory, $tracks);
                        $movie->update([
                            'conversion_status' => ConversionStatus::Playable,
                            'conversion_playable_at' => now(),
                        ]);
                        $published = true;
                    }
                });

            if (! $published) {
                if (! $readiness->isReady($directory, $tracks)) {
                    throw new \RuntimeException('FFmpeg a terminé sans produire de flux HLS lisible.');
                }
                $master->publish($directory, $tracks);
            }
            $movie->update([
                'conversion_status' => ConversionStatus::Converted,
                'conversion_playable_at' => $movie->conversion_playable_at ?? now(),
                'conversion_completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $movie->update([
                'conversion_status' => ConversionStatus::Failed,
                'conversion_error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
```

Le worker doit être lancé avec `php artisan queue:work --timeout=0`.

## `app/Http/Controllers/MovieConversionController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Jobs\ConvertMovie;
use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class MovieConversionController extends Controller
{
    public function store(Movie $movie): RedirectResponse
    {
        $started = Movie::query()->whereKey($movie->id)
            ->whereIn('conversion_status', [ConversionStatus::Pending->value, ConversionStatus::Failed->value])
            ->update(['conversion_status' => ConversionStatus::Queued, 'conversion_error' => null]);
        if ($started === 1) {
            ConvertMovie::dispatch($movie->id);
        }
        return to_route('movies.show', $movie);
    }

    public function show(Movie $movie): JsonResponse
    {
        return response()->json([
            'status' => $movie->conversion_status->value,
            'playable' => $movie->isPlayable(),
            'error' => $movie->conversion_status === ConversionStatus::Failed ? $movie->conversion_error : null,
        ]);
    }
}
```

## Méthodes HLS de `app/Http/Controllers/MovieController.php`

```php
public function show(Movie $movie): Response
{
    return Inertia::render('movies/show', ['movie' => [
        'id' => $movie->id,
        'title' => $movie->title,
        'filename' => $movie->filename,
        'conversion_status' => $movie->conversion_status->value,
        'conversion_error' => $movie->conversion_status === ConversionStatus::Failed ? $movie->conversion_error : null,
        'playable' => $movie->isPlayable(),
    ]]);
}

public function hlsManifest(Movie $movie): BinaryFileResponse
{
    abort_unless($movie->isPlayable(), 404);
    $path = $this->hlsPath($movie, 'index.m3u8');
    abort_unless(is_file($path), 404);
    return response()->file($path, [
        'Content-Type' => 'application/vnd.apple.mpegurl',
        'Cache-Control' => 'private, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
    ]);
}

public function hlsSegment(Movie $movie, string $segment): BinaryFileResponse
{
    abort_unless($movie->isPlayable(), 404);
    abort_unless(preg_match('/\A[a-zA-Z0-9_-]+\.(?:m3u8|ts|vtt|m4s|mp4)\z/', $segment) === 1, 404);
    $path = $this->hlsPath($movie, $segment);
    abort_unless(is_file($path), 404);
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    $contentType = match ($extension) {
        'm3u8' => 'application/vnd.apple.mpegurl', 'ts' => 'video/mp2t',
        'vtt' => 'text/vtt; charset=UTF-8', 'm4s' => 'video/iso.segment',
        'mp4' => 'video/mp4', default => 'application/octet-stream',
    };
    return response()->file($path, [
        'Content-Type' => $contentType,
        'Cache-Control' => $extension === 'm3u8' ? 'private, no-cache, must-revalidate' : 'private, max-age=31536000, immutable',
        'X-Content-Type-Options' => 'nosniff',
    ]);
}

private function hlsPath(Movie $movie, string $filename): string
{
    abort_if($movie->conversion_attempt === null, 404);
    return Storage::disk('public')->path("movies/{$movie->id}/hls/{$movie->conversion_attempt}/{$filename}");
}
```

Ces méthodes nécessitent notamment les imports `App\Enums\ConversionStatus`, `Illuminate\Support\Facades\Storage`, `Inertia\Response` et `Symfony\Component\HttpFoundation\BinaryFileResponse`.

## Routes à placer dans `routes/web.php`

```php
Route::get('/movies/{movie}', [MovieController::class, 'show'])->name('movies.show');
Route::post('/movies/{movie}/conversion', [MovieConversionController::class, 'store'])->name('movies.conversion.store');
Route::get('/movies/{movie}/conversion', [MovieConversionController::class, 'show'])->name('movies.conversion.show');
Route::get('/movies/{movie}/hls/index.m3u8', [MovieController::class, 'hlsManifest'])->name('movies.hls.manifest');
Route::get('/movies/{movie}/hls/{segment}', [MovieController::class, 'hlsSegment'])->name('movies.hls.segment');
```

## Structure de `resources/js/pages/movies/show.tsx`

```tsx
import { router } from '@inertiajs/react';
import Hls from 'hls.js';
import { useEffect, useRef, useState } from 'react';

type ConversionStatus = 'pending' | 'queued' | 'converting' | 'playable' | 'converted' | 'failed';
type Movie = {
    id: number; title: string; filename: string;
    conversion_status: ConversionStatus;
    conversion_error: string | null;
    playable: boolean;
};
type ConversionState = { status: ConversionStatus; playable: boolean; error: string | null };

function HlsPlayer({ src, language }: { src: string; language?: string }) {
    const videoRef = useRef<HTMLVideoElement>(null);
    useEffect(() => {
        const video = videoRef.current;
        if (!video) return;
        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = src;
            return;
        }
        if (!Hls.isSupported()) return;
        const hls = new Hls();
        hls.loadSource(src);
        hls.attachMedia(video);
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            hls.subtitleTrack = language
                ? hls.subtitleTracks.findIndex((track) => track.lang === language)
                : -1;
        });
        return () => hls.destroy();
    }, [language, src]);
    return <video ref={videoRef} controls preload="metadata" />;
}

export default function MovieShow({ movie }: { movie: Movie }) {
    const [conversion, setConversion] = useState<ConversionState>({
        status: movie.conversion_status,
        playable: movie.playable,
        error: movie.conversion_error,
    });
    const shouldPoll = conversion.status === 'queued' || conversion.status === 'converting';

    useEffect(() => {
        if (!shouldPoll) return;
        const interval = window.setInterval(async () => {
            const response = await fetch(`/movies/${movie.id}/conversion`, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin',
            });
            if (response.ok) setConversion(await response.json() as ConversionState);
        }, 2000);
        return () => window.clearInterval(interval);
    }, [movie.id, shouldPoll]);

    const start = () => router.post(`/movies/${movie.id}/conversion`, {}, {
        preserveScroll: true,
        onSuccess: () => setConversion({ status: 'queued', playable: false, error: null }),
    });

    return <main>
        <h1>{movie.title}</h1>
        {conversion.playable && <HlsPlayer src={`/movies/${movie.id}/hls/index.m3u8`} language="fr" />}
        {(conversion.status === 'pending' || conversion.status === 'failed') &&
            <button type="button" onClick={start}>{conversion.status === 'failed' ? 'Réessayer' : 'Watch movie'}</button>}
        {conversion.status === 'queued' && <p>Conversion en attente…</p>}
        {conversion.status === 'converting' && <p>Préparation de la vidéo…</p>}
        {conversion.status === 'playable' && <p>La conversion continue en arrière-plan.</p>}
        {conversion.status === 'failed' && <p>Échec : {conversion.error}</p>}
    </main>;
}
```

Dans le projet réel, les URL littérales de cet exemple doivent être remplacées par les fonctions Wayfinder générées correspondant aux routes nommées.
