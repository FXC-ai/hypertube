<?php

namespace App\Services\Media;

use App\Data\SelectedTracks;
use Illuminate\Support\Facades\Log;

final class HlsReadinessChecker
{
    public function isReady(string $directory, SelectedTracks $tracks): bool
    {
        Log::channel("my_debug")->debug("HlsReadinessChecker", ["isReady" => "is called"]);

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
        $playlistPath = $directory . DIRECTORY_SEPARATOR . $playlistName;

        Log::channel("my_debug")->debug("playlistHasExistingSegment", [$playlistPath]);

        if (! is_file($playlistPath)) {

            Log::channel("my_debug")->debug("HlsReadinessChecker", ["isReady return False : ", $playlistPath]);

            return false;
        }

        $lines = file($playlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            Log::channel("my_debug")->debug("HlsReadinessChecker", ["isReady return False : ", $lines]);
            return false;
        }

        foreach ($lines as $line) {
            $candidate = trim($line);

            if ($candidate === '' || str_starts_with($candidate, '#')) {
                continue;
            }

            if (preg_match('/\A[a-zA-Z0-9_-]+\.' . preg_quote($extension, '/') . '\z/', $candidate) !== 1) {
                continue;
            }

            $segmentPath = $directory . DIRECTORY_SEPARATOR . $candidate;

            if (is_file($segmentPath) && filesize($segmentPath) > 0) {
                Log::channel("my_debug")->debug("HlsReadinessChecker", ["isReady return True : ", $segmentPath, " exists"]);

                return true;
            }
        }

        Log::channel("my_debug")->debug("HlsReadinessChecker", ["isReady return False : ", $directory, $playlistName, $extension]);

        return false;
    }
}
