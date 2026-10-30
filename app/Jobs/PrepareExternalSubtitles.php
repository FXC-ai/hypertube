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
