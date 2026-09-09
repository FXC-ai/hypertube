# Conversion MKV vers HLS — tutoriel audité pour Hypertube

Ce document remplace la proposition précédente. Il cible le projet actuel : PHP 8.4, Laravel 13, Inertia Laravel 3, React 19, Hls.js 1.7 et SQLite. Il décrit une implémentation complète, mais ne modifie aucun des fichiers applicatifs cités ci-dessous.

## 1. Résultat et garanties

Le flux obtenu est le suivant :

1. L'utilisateur ouvre la page d'un film.
2. Un film `pending` affiche « Watch movie ».
3. Le clic effectue un `POST` et place atomiquement le film en `queued`.
4. Un job de queue revendique atomiquement le film et le place en `converting`.
5. FFprobe analyse toutes les pistes en un seul appel.
6. La première vidéo qui n'est pas une image attachée est retenue.
7. L'audio est choisi dans cet ordre : anglais, français, allemand, italien, puis première piste disponible.
8. Toutes les pistes de sous-titres **textuelles** en français, anglais, allemand ou italien sont retenues. Les sous-titres bitmap sont ignorés.
9. Un processus FFmpeg produit `video.m3u8`, ses segments MPEG-TS, et une playlist WebVTT par piste de sous-titres.
10. Quand la playlist vidéo et toutes les playlists de sous-titres annoncées possèdent un premier segment réellement présent, Laravel publie atomiquement `index.m3u8`, puis passe le film à `playable`.
11. React affiche immédiatement le lecteur. Le polling continue afin d'observer la fin ou un éventuel échec.
12. Lorsque FFmpeg termine correctement, le film passe à `converted`.

Deux limites doivent être dites explicitement :

- aucun logiciel ne peut promettre une conversion réussie pour tout MKV possible ; les codecs corrompus, les fichiers incomplets et les sous-titres bitmap peuvent être refusés ;
- si une piste de sous-titres ne contient aucun cue au début du film, sa première playlist segmentée peut apparaître tard. Pour ne jamais publier un manifeste qui référence une ressource inexistante, le statut `playable` attend la première ressource de chaque piste annoncée. C'est un compromis de cohérence, pas un bug.

## 2. Corrections issues de l'audit

L'ancien tutoriel contenait notamment les défauts suivants :

- l'ordre audio était `eng, fra, ita, deu`, contrairement à la règle demandée `eng, fra, deu, ita` ;
- toutes les langues de sous-titres textuels étaient conservées au lieu de limiter la sélection à `fra, eng, deu, ita` ;
- le client arrêtait le polling à `playable` et ne pouvait donc pas observer `converted` ou un échec tardif ;
- `onSuccess` imposait artificiellement `queued`, alors que le worker pouvait déjà avoir avancé ;
- les props Inertia étaient parfois à la racine et parfois sous `movie` ;
- `filepath` était stocké alors qu'il se déduit sans ambiguïté de l'ID et du nom de fichier ;
- l'ancienne vérification HLS ne précisait pas assez strictement que les segments référencés devaient exister ;
- la transition du job vers `converting` n'était pas revendiquée atomiquement ;
- un verrou temporel seul ne suffisait pas à empêcher un doublon différé ;
- les données de conversion n'étaient pas toutes remises à zéro lors d'une nouvelle tentative ;
- l'ancien exemple mélangeait une réponse Inertia et un état React indépendant sans synchronisation fiable ;
- la documentation Laravel 12 était citée alors que ce projet utilise Laravel 13.

La conception suivante corrige ces points.

## 3. Structure des fichiers

```text
app/
├── Data/
│   ├── MediaInfo.php
│   ├── MediaStream.php
│   └── SelectedTracks.php
├── Enums/ConversionStatus.php
├── Exceptions/MediaConversionException.php
├── Http/Controllers/
│   ├── MovieController.php
│   └── MovieConversionController.php
├── Jobs/ConvertMovie.php
├── Models/Movie.php
└── Services/Media/
    ├── HlsCommandBuilder.php
    ├── HlsConverter.php
    ├── HlsMasterPlaylistBuilder.php
    ├── HlsReadinessChecker.php
    ├── MediaProbe.php
    └── TrackSelector.php
config/media.php
database/factories/MovieFactory.php
database/migrations/..._add_conversion_fields_to_movies_table.php
resources/js/pages/movies/show.tsx
resources/js/types/movie.ts
routes/web.php
tests/Feature/MovieConversionControllerTest.php
tests/Unit/Services/Media/TrackSelectorTest.php
```

Le fichier source est toujours calculé ainsi :

```php
Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}")
```

Il n'existe donc aucune colonne `filepath` dans la version finale du schéma.

## 4. États et invariants

| Statut       | attempt | error   | started_at | playable_at               | completed_at     |
| ------------ | ------- | ------- | ---------- | ------------------------- | ---------------- |
| `pending`    | `null`  | `null`  | `null`     | `null`                    | `null`           |
| `queued`     | `null`  | `null`  | `null`     | `null`                    | `null`           |
| `converting` | UUID    | `null`  | date       | `null`                    | `null`           |
| `playable`   | UUID    | `null`  | date       | date postérieure          | `null`           |
| `converted`  | UUID    | `null`  | date       | date postérieure          | date postérieure |
| `failed`     | UUID    | message | date       | `null` ou date historique | `null`           |

Un échec peut survenir après `playable`. Dans ce cas, `conversion_playable_at` reste renseigné comme donnée historique, mais `isPlayable()` devient faux et le serveur refuse ensuite les fichiers HLS.

## 5. Code complet

### `app/Enums/ConversionStatus.php`

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

### `database/migrations/xxxx_xx_xx_xxxxxx_add_conversion_fields_to_movies_table.php`

Cette migration suppose que `movies` contient déjà `id`, `title`, `filename` et les timestamps. Si les migrations de développement peuvent encore être reconstruites, il est préférable de supprimer `filepath` directement de la migration de création plutôt que de créer une migration corrective supplémentaire.

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

### `app/Models/Movie.php`

```php
<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'title',
    'filename',
    'conversion_status',
    'conversion_attempt',
    'conversion_error',
    'conversion_started_at',
    'conversion_playable_at',
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

### `database/factories/MovieFactory.php`

La factory choisit un statut aléatoire par défaut. `withConversionStatus()` permet à un seeder ou un test de forcer un statut tout en conservant des champs cohérents.

```php
<?php

namespace Database\Factories;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Movie>
 */
class MovieFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        /** @var ConversionStatus $status */
        $status = fake()->randomElement(ConversionStatus::cases());

        return [
            'title' => fake()->sentence(3),
            'filename' => fake()->unique()->slug(3).'.mkv',
            ...$this->conversionAttributes($status),
        ];
    }

    public function withConversionStatus(ConversionStatus $status): static
    {
        return $this->state(fn (): array => $this->conversionAttributes($status));
    }

    /**
     * @return array{
     *     conversion_status: ConversionStatus,
     *     conversion_attempt: string|null,
     *     conversion_error: string|null,
     *     conversion_started_at: CarbonImmutable|null,
     *     conversion_playable_at: CarbonImmutable|null,
     *     conversion_completed_at: CarbonImmutable|null
     * }
     */
    private function conversionAttributes(ConversionStatus $status): array
    {
        $empty = [
            'conversion_status' => $status,
            'conversion_attempt' => null,
            'conversion_error' => null,
            'conversion_started_at' => null,
            'conversion_playable_at' => null,
            'conversion_completed_at' => null,
        ];

        if (in_array($status, [ConversionStatus::Pending, ConversionStatus::Queued], true)) {
            return $empty;
        }

        $startedAt = CarbonImmutable::instance(fake()->dateTimeBetween('-1 month', '-2 hours'));
        $playableAt = $startedAt->addSeconds(fake()->numberBetween(5, 1800));
        $attempt = (string) Str::uuid();

        return match ($status) {
            ConversionStatus::Converting => [
                ...$empty,
                'conversion_attempt' => $attempt,
                'conversion_started_at' => $startedAt,
            ],
            ConversionStatus::Playable => [
                ...$empty,
                'conversion_attempt' => $attempt,
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => $playableAt,
            ],
            ConversionStatus::Converted => [
                ...$empty,
                'conversion_attempt' => $attempt,
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => $playableAt,
                'conversion_completed_at' => $playableAt->addSeconds(fake()->numberBetween(1, 3600)),
            ],
            ConversionStatus::Failed => [
                ...$empty,
                'conversion_attempt' => $attempt,
                'conversion_error' => fake()->randomElement([
                    'FFprobe n’a trouvé aucune piste vidéo exploitable.',
                    'FFmpeg a terminé avec un code non nul.',
                    'Les ressources HLS produites ne sont pas lisibles.',
                ]),
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => fake()->boolean() ? $playableAt : null,
            ],
            default => unreachable(),
        };
    }
}
```

Exemple de seeder :

```php
Movie::factory()
    ->withConversionStatus(ConversionStatus::Converted)
    ->create(['filename' => 'sample-owned-or-licensed.mkv']);
```

### `config/media.php`

```php
<?php

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'hls' => [
        'segment_duration' => 6,
        'bandwidth' => 1_700_000,
    ],
];
```

Exemple Windows dans `.env` :

```dotenv
FFMPEG_BINARY=C:\ffmpeg\bin\ffmpeg.exe
FFPROBE_BINARY=C:\ffmpeg\bin\ffprobe.exe
```

### `app/Exceptions/MediaConversionException.php`

```php
<?php

namespace App\Exceptions;

use RuntimeException;

final class MediaConversionException extends RuntimeException
{
}
```

### `app/Data/MediaStream.php`

```php
<?php

namespace App\Data;

final readonly class MediaStream
{
    /**
     * @param array<string, mixed> $disposition
     */
    public function __construct(
        public int $index,
        public string $type,
        public string $codec,
        public string $language,
        public string $title,
        public array $disposition,
    ) {
    }

    public function isAttachedPicture(): bool
    {
        return (bool) ($this->disposition['attached_pic'] ?? false);
    }

    public function isTextSubtitle(): bool
    {
        return in_array($this->codec, [
            'subrip',
            'srt',
            'ass',
            'ssa',
            'webvtt',
            'mov_text',
            'text',
        ], true);
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

### `app/Data/MediaInfo.php`

```php
<?php

namespace App\Data;

final readonly class MediaInfo
{
    /** @param list<MediaStream> $streams */
    public function __construct(public array $streams)
    {
    }

    /** @return list<MediaStream> */
    public function videos(): array
    {
        return array_values(array_filter(
            $this->streams,
            fn (MediaStream $stream): bool => $stream->type === 'video'
                && ! $stream->isAttachedPicture(),
        ));
    }

    /** @return list<MediaStream> */
    public function audios(): array
    {
        return array_values(array_filter(
            $this->streams,
            fn (MediaStream $stream): bool => $stream->type === 'audio',
        ));
    }

    /** @return list<MediaStream> */
    public function subtitles(): array
    {
        return array_values(array_filter(
            $this->streams,
            fn (MediaStream $stream): bool => $stream->type === 'subtitle'
                && $stream->isTextSubtitle(),
        ));
    }
}
```

### `app/Data/SelectedTracks.php`

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
    ) {
    }
}
```

### `app/Services/Media/MediaProbe.php`

FFprobe reçoit un tableau d'arguments : aucun nom de fichier n'est concaténé dans une commande shell.

```php
<?php

namespace App\Services\Media;

use App\Data\MediaInfo;
use App\Data\MediaStream;
use App\Exceptions\MediaConversionException;
use JsonException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class MediaProbe
{
    public function probe(string $inputPath): MediaInfo
    {
        if (! is_file($inputPath)) {
            throw new MediaConversionException('Le fichier source est introuvable.');
        }

        $process = new Process([
            (string) config('media.ffprobe_binary'),
            '-v',
            'error',
            '-show_streams',
            '-of',
            'json',
            $inputPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new MediaConversionException(
                'FFprobe a échoué : '.$this->errorSummary($process),
                previous: new ProcessFailedException($process),
            );
        }

        try {
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MediaConversionException(
                'FFprobe a retourné un JSON invalide.',
                previous: $exception,
            );
        }

        if (! is_array($decoded) || ! is_array($decoded['streams'] ?? null)) {
            throw new MediaConversionException('La réponse FFprobe ne contient aucune liste de pistes.');
        }

        $streams = [];

        foreach ($decoded['streams'] as $rawStream) {
            if (! is_array($rawStream)) {
                continue;
            }

            $tags = is_array($rawStream['tags'] ?? null) ? $rawStream['tags'] : [];
            $disposition = is_array($rawStream['disposition'] ?? null)
                ? $rawStream['disposition']
                : [];

            $streams[] = new MediaStream(
                index: (int) ($rawStream['index'] ?? -1),
                type: (string) ($rawStream['codec_type'] ?? ''),
                codec: strtolower((string) ($rawStream['codec_name'] ?? '')),
                language: $this->normalizeLanguage(
                    is_string($tags['language'] ?? null) ? $tags['language'] : null,
                ),
                title: is_string($tags['title'] ?? null) ? $tags['title'] : '',
                disposition: $disposition,
            );
        }

        return new MediaInfo($streams);
    }

    private function normalizeLanguage(?string $language): string
    {
        return match (strtolower(trim($language ?? 'und'))) {
            'fra', 'fre', 'fr', 'french' => 'fra',
            'eng', 'en', 'english' => 'eng',
            'deu', 'ger', 'de', 'german' => 'deu',
            'ita', 'it', 'italian' => 'ita',
            default => 'und',
        };
    }

    private function errorSummary(Process $process): string
    {
        $message = trim($process->getErrorOutput());

        return mb_substr($message !== '' ? $message : 'erreur inconnue', 0, 1000);
    }
}
```

### `app/Services/Media/TrackSelector.php`

Toutes les pistes de sous-titres textuelles appartenant aux quatre langues sont conservées, y compris plusieurs pistes dans la même langue.

```php
<?php

namespace App\Services\Media;

use App\Data\MediaInfo;
use App\Data\MediaStream;
use App\Data\SelectedTracks;
use App\Exceptions\MediaConversionException;

final class TrackSelector
{
    public function select(MediaInfo $media): SelectedTracks
    {
        $videos = $media->videos();

        if ($videos === []) {
            throw new MediaConversionException('Aucune piste vidéo exploitable.');
        }

        return new SelectedTracks(
            video: $videos[0],
            audio: $this->selectAudio($media->audios()),
            subtitles: array_values(array_filter(
                $media->subtitles(),
                fn (MediaStream $stream): bool => in_array(
                    $stream->language,
                    ['fra', 'eng', 'deu', 'ita'],
                    true,
                ),
            )),
        );
    }

    /** @param list<MediaStream> $audios */
    private function selectAudio(array $audios): ?MediaStream
    {
        foreach (['eng', 'fra', 'deu', 'ita'] as $preferredLanguage) {
            foreach ($audios as $audio) {
                if ($audio->language === $preferredLanguage) {
                    return $audio;
                }
            }
        }

        return $audios[0] ?? null;
    }
}
```

### `app/Services/Media/HlsCommandBuilder.php`

`-segment_list_flags +live` force les playlists de sous-titres à être mises à jour pendant le traitement. `-progress pipe:1` fournit régulièrement de la sortie à Symfony Process afin que Laravel puisse tester la disponibilité des premières ressources pendant l'encodage.

```php
<?php

namespace App\Services\Media;

use App\Data\SelectedTracks;

final class HlsCommandBuilder
{
    /** @return list<string> */
    public function build(string $inputPath, string $outputDirectory, SelectedTracks $tracks): array
    {
        $segmentDuration = (int) config('media.hls.segment_duration', 6);
        $separator = DIRECTORY_SEPARATOR;

        $arguments = [
            (string) config('media.ffmpeg_binary'),
            '-hide_banner',
            '-y',
            '-progress',
            'pipe:1',
            '-nostats',
            '-i',
            $inputPath,
            '-map',
            "0:{$tracks->video->index}",
        ];

        if ($tracks->audio !== null) {
            array_push($arguments, '-map', "0:{$tracks->audio->index}");
        }

        array_push(
            $arguments,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '23',
            '-pix_fmt',
            'yuv420p',
            '-sc_threshold',
            '0',
            '-force_key_frames',
            "expr:gte(t,n_forced*{$segmentDuration})",
        );

        if ($tracks->audio !== null) {
            array_push($arguments, '-c:a', 'aac', '-b:a', '128k', '-ac', '2');
        }

        array_push(
            $arguments,
            '-f',
            'hls',
            '-hls_time',
            (string) $segmentDuration,
            '-hls_playlist_type',
            'event',
            '-hls_list_size',
            '0',
            '-hls_flags',
            'independent_segments+temp_file',
            '-hls_segment_filename',
            $outputDirectory.$separator.'video_%05d.ts',
            $outputDirectory.$separator.'video.m3u8',
        );

        foreach ($tracks->subtitles as $position => $subtitle) {
            $prefix = sprintf('subtitle_%02d_%s', $position, $subtitle->language);

            array_push(
                $arguments,
                '-map',
                "0:{$subtitle->index}",
                '-c:s',
                'webvtt',
                '-f',
                'segment',
                '-segment_time',
                (string) $segmentDuration,
                '-segment_list',
                $outputDirectory.$separator."{$prefix}.m3u8",
                '-segment_list_type',
                'm3u8',
                '-segment_list_flags',
                '+live',
                '-segment_list_size',
                '0',
                '-reset_timestamps',
                '1',
                $outputDirectory.$separator."{$prefix}_%05d.vtt",
            );
        }

        return $arguments;
    }
}
```

### `app/Services/Media/HlsReadinessChecker.php`

Une playlist n'est considérée prête que si elle contient une URI locale simple et si le fichier correspondant existe et n'est pas vide.

```php
<?php

namespace App\Services\Media;

use App\Data\SelectedTracks;

final class HlsReadinessChecker
{
    public function isReady(string $directory, SelectedTracks $tracks): bool
    {
        if (! $this->playlistHasExistingSegment($directory, 'video.m3u8', 'ts')) {
            return false;
        }

        foreach ($tracks->subtitles as $position => $subtitle) {
            $playlist = sprintf('subtitle_%02d_%s.m3u8', $position, $subtitle->language);

            if (! $this->playlistHasExistingSegment($directory, $playlist, 'vtt')) {
                return false;
            }
        }

        return true;
    }

    private function playlistHasExistingSegment(
        string $directory,
        string $playlistName,
        string $extension,
    ): bool {
        $playlistPath = $directory.DIRECTORY_SEPARATOR.$playlistName;

        if (! is_file($playlistPath)) {
            return false;
        }

        $lines = file($playlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $candidate = trim($line);

            if ($candidate === '' || str_starts_with($candidate, '#')) {
                continue;
            }

            if (preg_match('/\A[a-zA-Z0-9_-]+\.'.preg_quote($extension, '/').'\z/', $candidate) !== 1) {
                continue;
            }

            $segmentPath = $directory.DIRECTORY_SEPARATOR.$candidate;

            if (is_file($segmentPath) && filesize($segmentPath) > 0) {
                return true;
            }
        }

        return false;
    }
}
```

### `app/Services/Media/HlsMasterPlaylistBuilder.php`

Le manifeste maître n'est publié qu'après validation de toutes ses dépendances. Les titres provenant des métadonnées sont nettoyés avant insertion.

```php
<?php

namespace App\Services\Media;

use App\Data\MediaStream;
use App\Data\SelectedTracks;
use App\Exceptions\MediaConversionException;

final class HlsMasterPlaylistBuilder
{
    public function publish(string $directory, SelectedTracks $tracks): void
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

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

        $stream = '#EXT-X-STREAM-INF:BANDWIDTH='.(int) config('media.hls.bandwidth', 1_700_000);

        if ($tracks->subtitles !== []) {
            $stream .= ',SUBTITLES="subtitles"';
        }

        $lines[] = $stream;
        $lines[] = 'video.m3u8';

        $temporaryPath = $directory.DIRECTORY_SEPARATOR.'index.m3u8.tmp';
        $finalPath = $directory.DIRECTORY_SEPARATOR.'index.m3u8';
        $contents = implode("\n", $lines)."\n";

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new MediaConversionException('Impossible d’écrire le manifeste HLS temporaire.');
        }

        if (! rename($temporaryPath, $finalPath)) {
            @unlink($temporaryPath);

            throw new MediaConversionException('Impossible de publier le manifeste HLS.');
        }
    }

    private function displayName(MediaStream $subtitle, int $position): string
    {
        if (trim($subtitle->title) !== '') {
            return trim($subtitle->title).' '.($position + 1);
        }

        return match ($subtitle->language) {
            'fra' => 'Français '.($position + 1),
            'eng' => 'English '.($position + 1),
            'deu' => 'Deutsch '.($position + 1),
            'ita' => 'Italiano '.($position + 1),
            default => 'Subtitle '.($position + 1),
        };
    }

    private function attribute(string $value): string
    {
        $singleLine = str_replace(["\r", "\n"], ' ', $value);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $singleLine);
    }
}
```

### `app/Services/Media/HlsConverter.php`

La callback est limitée à environ deux appels par seconde pour éviter de relire les playlists à chaque octet produit par FFmpeg.

```php
<?php

namespace App\Services\Media;

use App\Exceptions\MediaConversionException;
use Symfony\Component\Process\Process;

final class HlsConverter
{
    /**
     * @param list<string> $command
     * @param callable(): void $onProgress
     */
    public function convert(array $command, callable $onProgress): void
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $lastCheck = 0.0;

        $exitCode = $process->run(function () use ($onProgress, &$lastCheck): void {
            $now = microtime(true);

            if ($now - $lastCheck >= 0.5) {
                $onProgress();
                $lastCheck = $now;
            }
        });

        $onProgress();

        if ($exitCode !== 0) {
            $error = trim($process->getErrorOutput());

            throw new MediaConversionException(
                'FFmpeg a échoué : '.mb_substr($error !== '' ? $error : 'erreur inconnue', 0, 2000),
            );
        }
    }
}
```

### `app/Jobs/ConvertMovie.php`

Le job ne fait pas confiance au simple fait d'avoir été distribué : il revendique la ligne par une mise à jour conditionnelle `queued → converting`. Même si un doublon existe dans la queue, un seul job peut obtenir la ligne.

```php
<?php

namespace App\Jobs;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use App\Services\Media\HlsCommandBuilder;
use App\Services\Media\HlsConverter;
use App\Services\Media\HlsMasterPlaylistBuilder;
use App\Services\Media\HlsReadinessChecker;
use App\Services\Media\MediaProbe;
use App\Services\Media\TrackSelector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ConvertMovie implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public int $movieId)
    {
    }

    public function handle(
        MediaProbe $probe,
        TrackSelector $selector,
        HlsCommandBuilder $commands,
        HlsReadinessChecker $readiness,
        HlsMasterPlaylistBuilder $masterPlaylist,
        HlsConverter $converter,
    ): void {
        $attempt = (string) Str::uuid();
        $startedAt = now();

        $claimed = Movie::query()
            ->whereKey($this->movieId)
            ->where('conversion_status', ConversionStatus::Queued->value)
            ->update([
                'conversion_status' => ConversionStatus::Converting->value,
                'conversion_attempt' => $attempt,
                'conversion_error' => null,
                'conversion_started_at' => $startedAt,
                'conversion_playable_at' => null,
                'conversion_completed_at' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $movie = Movie::query()->findOrFail($this->movieId);

        try {
            if (preg_match('/[\\\\\/]/', $movie->filename) === 1
                || $movie->filename !== basename($movie->filename)) {
                throw new \RuntimeException('Nom de fichier source invalide.');
            }

            $inputPath = Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}");
            $outputDirectory = Storage::disk('public')->path("movies/{$movie->id}/hls/{$attempt}");

            if (! is_dir($outputDirectory)
                && ! mkdir($outputDirectory, 0755, true)
                && ! is_dir($outputDirectory)) {
                throw new \RuntimeException('Impossible de créer le dossier HLS.');
            }

            $tracks = $selector->select($probe->probe($inputPath));
            $published = false;

            $publishWhenReady = function () use (
                $movie,
                $attempt,
                $outputDirectory,
                $tracks,
                $readiness,
                $masterPlaylist,
                &$published,
            ): void {
                if ($published || ! $readiness->isReady($outputDirectory, $tracks)) {
                    return;
                }

                $masterPlaylist->publish($outputDirectory, $tracks);

                $updated = Movie::query()
                    ->whereKey($movie->id)
                    ->where('conversion_attempt', $attempt)
                    ->where('conversion_status', ConversionStatus::Converting->value)
                    ->update([
                        'conversion_status' => ConversionStatus::Playable->value,
                        'conversion_playable_at' => now(),
                    ]);

                $published = $updated === 1;
            };

            $converter->convert(
                $commands->build($inputPath, $outputDirectory, $tracks),
                $publishWhenReady,
            );

            $publishWhenReady();

            if (! $published) {
                throw new \RuntimeException('FFmpeg a terminé sans produire de flux HLS lisible.');
            }

            Movie::query()
                ->whereKey($movie->id)
                ->where('conversion_attempt', $attempt)
                ->where('conversion_status', ConversionStatus::Playable->value)
                ->update([
                    'conversion_status' => ConversionStatus::Converted->value,
                    'conversion_completed_at' => now(),
                ]);
        } catch (Throwable $exception) {
            Movie::query()
                ->whereKey($movie->id)
                ->where('conversion_attempt', $attempt)
                ->whereIn('conversion_status', [
                    ConversionStatus::Converting->value,
                    ConversionStatus::Playable->value,
                ])
                ->update([
                    'conversion_status' => ConversionStatus::Failed->value,
                    'conversion_error' => Str::limit($exception->getMessage(), 4000),
                    'conversion_completed_at' => null,
                ]);

            Log::error('Échec de conversion HLS.', [
                'movie_id' => $movie->id,
                'attempt' => $attempt,
                'exception' => $exception,
            ]);
        }
    }
}
```

Le job capture l'erreur métier et laisse la ligne en `failed`; il ne relance pas automatiquement exactement la même entrée. L'utilisateur peut déclencher une nouvelle tentative, qui obtient un nouvel UUID et un nouveau dossier.

### `app/Http/Controllers/MovieConversionController.php`

Le contrôleur remet **tous** les champs de tentative à zéro. La mise à jour conditionnelle rend deux clics concurrents inoffensifs.

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
        $queued = Movie::query()
            ->whereKey($movie->id)
            ->whereIn('conversion_status', [
                ConversionStatus::Pending->value,
                ConversionStatus::Failed->value,
            ])
            ->update([
                'conversion_status' => ConversionStatus::Queued->value,
                'conversion_attempt' => null,
                'conversion_error' => null,
                'conversion_started_at' => null,
                'conversion_playable_at' => null,
                'conversion_completed_at' => null,
            ]);

        if ($queued === 1) {
            ConvertMovie::dispatch($movie->id)->afterCommit();
        }

        return to_route('movies.show', $movie);
    }

    public function show(Movie $movie): JsonResponse
    {
        return response()->json([
            'status' => $movie->conversion_status->value,
            'playable' => $movie->isPlayable(),
            'error' => $movie->conversion_status === ConversionStatus::Failed
                ? $movie->conversion_error
                : null,
        ]);
    }
}
```

`onSuccess` côté Inertia signifie seulement que la visite a abouti. Il ne prouve pas que `$queued === 1` et ne doit jamais inventer le statut `queued` côté client.

### Méthodes HLS et `show()` dans `app/Http/Controllers/MovieController.php`

Ces méthodes doivent être intégrées à la classe existante. Le code ci-dessous montre la classe minimale complète consacrée à ce tutoriel.

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class MovieController extends Controller
{
    public function show(Movie $movie): Response
    {
        return Inertia::render('movies/show', [
            'movie' => $this->moviePayload($movie),
        ]);
    }

    public function hlsManifest(Movie $movie): BinaryFileResponse
    {
        return $this->hlsResponse($movie, 'index.m3u8');
    }

    public function hlsSegment(Movie $movie, string $segment): BinaryFileResponse
    {
        abort_unless(
            preg_match('/\A[a-zA-Z0-9_-]+\.(?:m3u8|ts|vtt)\z/', $segment) === 1,
            404,
        );

        return $this->hlsResponse($movie, $segment);
    }

    /** @return array<string, int|string|bool|null> */
    private function moviePayload(Movie $movie): array
    {
        return [
            'id' => $movie->id,
            'title' => $movie->title,
            'filename' => $movie->filename,
            'conversion_status' => $movie->conversion_status->value,
            'conversion_error' => $movie->conversion_status === ConversionStatus::Failed
                ? $movie->conversion_error
                : null,
            'playable' => $movie->isPlayable(),
        ];
    }

    private function hlsResponse(Movie $movie, string $filename): BinaryFileResponse
    {
        abort_unless($movie->isPlayable(), 404);
        abort_if($movie->conversion_attempt === null, 404);

        $path = Storage::disk('public')->path(
            "movies/{$movie->id}/hls/{$movie->conversion_attempt}/{$filename}",
        );

        abort_unless(is_file($path), 404);

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $contentType = match ($extension) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'vtt' => 'text/vtt; charset=UTF-8',
            default => 'application/octet-stream',
        };

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Cache-Control' => $extension === 'm3u8'
                ? 'private, no-cache, must-revalidate'
                : 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

Le groupe de routes est authentifié. Dans une application où les films ne sont pas accessibles à tous les utilisateurs connectés, ajouter aussi une Policy dans les trois contrôleurs.

### `routes/web.php`

Les autres routes du projet peuvent rester présentes ; voici le bloc complet relatif aux films.

```php
<?php

use App\Http\Controllers\MovieController;
use App\Http\Controllers\MovieConversionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/movies/{movie}', [MovieController::class, 'show'])
        ->name('movies.show');

    Route::post('/movies/{movie}/conversion', [MovieConversionController::class, 'store'])
        ->name('movies.conversion.store');

    Route::get('/movies/{movie}/conversion', [MovieConversionController::class, 'show'])
        ->name('movies.conversion.show');

    Route::get('/movies/{movie}/hls/index.m3u8', [MovieController::class, 'hlsManifest'])
        ->name('movies.hls.manifest');

    Route::get('/movies/{movie}/hls/{segment}', [MovieController::class, 'hlsSegment'])
        ->name('movies.hls.segment');
});
```

Après modification des routes ou signatures de contrôleurs, Wayfinder régénère normalement ses fichiers via Vite. On peut aussi utiliser la commande Wayfinder proposée par `php artisan list` dans la version installée du projet.

### `resources/js/types/movie.ts`

Ce type représente le **payload de page**, pas les colonnes SQL. `playable` est donc légitime : il est calculé par `Movie::isPlayable()` puis ajouté par le contrôleur.

```ts
export type ConversionStatus =
    'pending' | 'queued' | 'converting' | 'playable' | 'converted' | 'failed';

export type MoviePageData = {
    id: number;
    title: string;
    filename: string;
    conversion_status: ConversionStatus;
    conversion_error: string | null;
    playable: boolean;
};

export type ConversionState = {
    status: ConversionStatus;
    playable: boolean;
    error: string | null;
};
```

### `resources/js/pages/movies/show.tsx`

Points importants :

- le `POST` n'applique aucun faux statut optimiste ; `onSuccess` reprend les props réellement renvoyées après la redirection ;
- le polling utilise l'endpoint JSON et continue pendant `playable` ;
- un `setTimeout` récursif évite d'empiler plusieurs requêtes lentes ;
- le nettoyage annule logiquement la boucle lorsque le composant disparaît ;
- Wayfinder fournit les URL avec `.url(...)`.

```tsx
import { manifest } from '@/routes/movies/hls';
import {
    show as conversionShow,
    store as conversionStore,
} from '@/routes/movies/conversion';
import type { ConversionState, MoviePageData } from '@/types/movie';
import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import Hls from 'hls.js';
import { useEffect, useRef, useState } from 'react';

type MovieShowProps = {
    movie: MoviePageData;
};

function stateFromMovie(movie: MoviePageData): ConversionState {
    return {
        status: movie.conversion_status,
        playable: movie.playable,
        error: movie.conversion_error,
    };
}

function HlsPlayer({ src }: { src: string }) {
    const videoRef = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        const video = videoRef.current;

        if (video === null) {
            return;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = src;

            return () => {
                video.removeAttribute('src');
                video.load();
            };
        }

        if (!Hls.isSupported()) {
            return;
        }

        const hls = new Hls();
        hls.loadSource(src);
        hls.attachMedia(video);

        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            const preferredLanguage = 'fr';
            const subtitleIndex = hls.subtitleTracks.findIndex(
                (track) => track.lang === preferredLanguage,
            );

            hls.subtitleTrack = subtitleIndex;
        });

        return () => {
            hls.destroy();
        };
    }, [src]);

    return <video ref={videoRef} controls preload="metadata" />;
}

export default function MovieShow({ movie }: MovieShowProps) {
    const [conversion, setConversion] = useState<ConversionState>(() =>
        stateFromMovie(movie),
    );
    const [isStarting, setIsStarting] = useState(false);

    useEffect(() => {
        setConversion(stateFromMovie(movie));
    }, [movie]);

    useEffect(() => {
        const shouldPoll = ['queued', 'converting', 'playable'].includes(
            conversion.status,
        );

        if (!shouldPoll) {
            return;
        }

        let cancelled = false;
        let timer: number | undefined;

        const poll = async (): Promise<void> => {
            try {
                const response = await fetch(conversionShow.url(movie.id), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const nextState = (await response.json()) as ConversionState;

                if (!cancelled) {
                    setConversion(nextState);
                }
            } catch {
                // Une panne réseau transitoire ne change pas l'état métier affiché.
            } finally {
                if (!cancelled) {
                    timer = window.setTimeout(() => void poll(), 2000);
                }
            }
        };

        timer = window.setTimeout(() => void poll(), 2000);

        return () => {
            cancelled = true;

            if (timer !== undefined) {
                window.clearTimeout(timer);
            }
        };
    }, [conversion.status, movie.id]);

    const start = (): void => {
        router.post(
            conversionStore.url(movie.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setIsStarting(true),
                onSuccess: (page: Page) => {
                    const updatedMovie = page.props.movie as MoviePageData;
                    setConversion(stateFromMovie(updatedMovie));
                },
                onFinish: () => setIsStarting(false),
            },
        );
    };

    return (
        <main>
            <h1>{movie.title}</h1>

            {conversion.playable && <HlsPlayer src={manifest.url(movie.id)} />}

            {(conversion.status === 'pending' ||
                conversion.status === 'failed') && (
                <button type="button" disabled={isStarting} onClick={start}>
                    {conversion.status === 'failed'
                        ? 'Réessayer'
                        : 'Watch movie'}
                </button>
            )}

            {conversion.status === 'queued' && <p>Conversion en attente…</p>}
            {conversion.status === 'converting' && (
                <p>Préparation de la vidéo…</p>
            )}
            {conversion.status === 'playable' && (
                <p>La lecture est disponible ; la conversion continue.</p>
            )}
            {conversion.status === 'converted' && <p>Conversion terminée.</p>}
            {conversion.status === 'failed' && conversion.error !== null && (
                <p>Échec de la conversion : {conversion.error}</p>
            )}
        </main>
    );
}
```

## 6. Tests indispensables

Les extraits suivants sont des fichiers complets. Ils testent la logique déterministe sans lancer FFmpeg. Un test d'intégration réel est décrit ensuite.

### `tests/Unit/Services/Media/TrackSelectorTest.php`

```php
<?php

use App\Data\MediaInfo;
use App\Data\MediaStream;
use App\Exceptions\MediaConversionException;
use App\Services\Media\TrackSelector;

function stream(
    int $index,
    string $type,
    string $language = 'und',
    string $codec = 'h264',
    bool $attachedPicture = false,
): MediaStream {
    return new MediaStream(
        index: $index,
        type: $type,
        codec: $codec,
        language: $language,
        title: '',
        disposition: ['attached_pic' => $attachedPicture ? 1 : 0],
    );
}

it('selects the first real video and ignores cover art', function () {
    $selected = (new TrackSelector)->select(new MediaInfo([
        stream(0, 'video', attachedPicture: true),
        stream(1, 'video'),
        stream(2, 'video'),
    ]));

    expect($selected->video->index)->toBe(1);
});

it('fails when no real video exists', function () {
    (new TrackSelector)->select(new MediaInfo([
        stream(0, 'video', attachedPicture: true),
        stream(1, 'audio', 'eng', 'aac'),
    ]));
})->throws(MediaConversionException::class, 'Aucune piste vidéo exploitable.');

it('uses the required audio priority', function () {
    $selected = (new TrackSelector)->select(new MediaInfo([
        stream(0, 'video'),
        stream(1, 'audio', 'ita', 'aac'),
        stream(2, 'audio', 'deu', 'aac'),
        stream(3, 'audio', 'fra', 'aac'),
        stream(4, 'audio', 'eng', 'aac'),
    ]));

    expect($selected->audio?->index)->toBe(4);
});

it('falls back to the first audio when no preferred language exists', function () {
    $selected = (new TrackSelector)->select(new MediaInfo([
        stream(0, 'video'),
        stream(5, 'audio', 'jpn', 'aac'),
        stream(6, 'audio', 'spa', 'aac'),
    ]));

    expect($selected->audio?->index)->toBe(5);
});

it('keeps only textual subtitles in supported languages', function () {
    $selected = (new TrackSelector)->select(new MediaInfo([
        stream(0, 'video'),
        stream(1, 'subtitle', 'fra', 'subrip'),
        stream(2, 'subtitle', 'eng', 'ass'),
        stream(3, 'subtitle', 'deu', 'hdmv_pgs_subtitle'),
        stream(4, 'subtitle', 'ita', 'webvtt'),
        stream(5, 'subtitle', 'spa', 'subrip'),
    ]));

    expect(array_map(
        fn (MediaStream $subtitle): int => $subtitle->index,
        $selected->subtitles,
    ))->toBe([1, 2, 4]);
});
```

### `tests/Feature/MovieConversionControllerTest.php`

```php
<?php

use App\Enums\ConversionStatus;
use App\Jobs\ConvertMovie;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('queues one conversion and resets previous attempt data', function () {
    Bus::fake();

    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Failed)
        ->create();

    $this->actingAs(User::factory()->create())
        ->post(route('movies.conversion.store', $movie))
        ->assertRedirect(route('movies.show', $movie));

    $movie->refresh();

    expect($movie->conversion_status)->toBe(ConversionStatus::Queued)
        ->and($movie->conversion_attempt)->toBeNull()
        ->and($movie->conversion_error)->toBeNull()
        ->and($movie->conversion_started_at)->toBeNull()
        ->and($movie->conversion_playable_at)->toBeNull()
        ->and($movie->conversion_completed_at)->toBeNull();

    Bus::assertDispatchedTimes(ConvertMovie::class, 1);
});

it('does not dispatch another job for an active conversion', function () {
    Bus::fake();

    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Converting)
        ->create();

    $this->actingAs(User::factory()->create())
        ->post(route('movies.conversion.store', $movie))
        ->assertRedirect(route('movies.show', $movie));

    Bus::assertNotDispatched(ConvertMovie::class);
});

it('returns the authoritative conversion state as json', function () {
    $movie = Movie::factory()
        ->withConversionStatus(ConversionStatus::Playable)
        ->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('movies.conversion.show', $movie))
        ->assertOk()
        ->assertExactJson([
            'status' => 'playable',
            'playable' => true,
            'error' => null,
        ]);
});
```

### Tests encore requis avant production

Créer également des tests dédiés pour :

- le JSON FFprobe valide, invalide et sans `streams` ;
- la normalisation `fre/fra`, `ger/deu`, formes à deux lettres et langue absente ;
- `HlsCommandBuilder` sans audio, sans sous-titres et avec quatre sous-titres ;
- l'absence de `var_stream_map` dans la commande ;
- `HlsReadinessChecker` avec playlist absente, segment absent, segment vide et segment valide ;
- l'échappement des attributs du manifeste maître ;
- le refus des noms contenant `/`, `\\`, `..` ou une extension interdite ;
- les transitions atomiques du job et le cas d'un job dupliqué ;
- un échec avant `playable` et un échec après `playable` ;
- un vrai petit MKV, créé par l'équipe ou sous licence compatible, contenant vidéo, audio et quatre sous-titres textuels.

Le test réel FFmpeg doit être marqué comme test d'intégration et ignoré explicitement lorsque les binaires ne sont pas disponibles. Il doit vérifier avec `ffprobe` le fichier fixture, lancer le job avec la queue `sync`, puis contrôler :

```text
index.m3u8
video.m3u8
video_00000.ts (ou le premier numéro réellement produit)
une playlist .m3u8 par piste de sous-titres retenue
au moins un .vtt référencé par chacune
conversion_status = converted
started_at < playable_at < completed_at
```

## 7. Installation et vérification

Créer les classes avec les commandes Artisan adaptées, par exemple :

```bash
php artisan make:class Data/MediaStream --no-interaction
php artisan make:class Data/MediaInfo --no-interaction
php artisan make:class Data/SelectedTracks --no-interaction
php artisan make:class Services/Media/MediaProbe --no-interaction
php artisan make:class Services/Media/TrackSelector --no-interaction
php artisan make:class Services/Media/HlsCommandBuilder --no-interaction
php artisan make:class Services/Media/HlsReadinessChecker --no-interaction
php artisan make:class Services/Media/HlsMasterPlaylistBuilder --no-interaction
php artisan make:class Services/Media/HlsConverter --no-interaction
php artisan make:job ConvertMovie --no-interaction
php artisan make:controller MovieConversionController --no-interaction
php artisan make:test --pest MovieConversionControllerTest --no-interaction
php artisan make:test --pest --unit Services/Media/TrackSelectorTest --no-interaction
```

Puis appliquer le code, générer les routes Wayfinder selon la commande disponible dans `php artisan list`, et exécuter :

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/Services/Media/TrackSelectorTest.php
php artisan test --compact tests/Feature/MovieConversionControllerTest.php
npm run types:check
npm run lint:check
```

Lancer enfin l'application, le worker et Vite :

```bash
composer run dev
```

Le worker doit accepter les conversions longues. Le script `composer run dev` du projet utilise déjà `queue:listen --tries=1 --timeout=0`.

## 8. Checklist fonctionnelle manuelle

1. Ouvrir un film `pending` : seul le bouton de démarrage apparaît.
2. Cliquer deux fois rapidement : un seul job est distribué.
3. Observer `queued`, puis `converting` via l'endpoint JSON.
4. Vérifier que le dossier correspond à l'UUID de `conversion_attempt`.
5. Dès que toutes les premières ressources existent, vérifier `playable` et l'apparition du lecteur.
6. Commencer la lecture pendant que la taille du dossier continue d'augmenter.
7. Vérifier que le polling continue pendant `playable`.
8. Vérifier le passage final à `converted`.
9. Provoquer un échec FFmpeg et vérifier `failed`, le message et la disparition de l'accès HLS.
10. Cliquer sur « Réessayer » et vérifier la création d'un nouvel UUID sans mélange avec l'ancien dossier.

## 9. Références techniques utilisées pour l'audit

- [documentation Laravel 13 sur les queues](https://laravel.com/docs/13.x/queues), notamment les jobs dans les transactions et `afterCommit()` ;
- [documentation Inertia 3 sur les visites manuelles](https://inertiajs.com/docs/v3/the-basics/manual-visits) et [le polling](https://inertiajs.com/docs/v3/data-props/polling) ;
- documentation Symfony Process correspondant aux dépendances installées ;
- [documentation FFprobe](https://ffmpeg.org/ffprobe.html) et [documentation des muxers HLS et segment de FFmpeg](https://ffmpeg.org/ffmpeg-formats.html) ;
- code généré par Wayfinder 0.1 installé dans ce projet (`route.url(...)`).

Cette version garde une seule autorité métier : la base de données. Le client ne devine jamais une transition, le job ne traite jamais deux fois une ligne revendiquée, et aucun manifeste maître n'est rendu visible avant que toutes les ressources qu'il annonce existent réellement.
