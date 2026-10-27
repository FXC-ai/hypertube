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
