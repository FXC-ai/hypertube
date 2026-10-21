<?php

namespace App\Services\Torrent;

final class TorrentVideoSelector
{
    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = ['mp4', 'mkv'];

    /**
     * @param  list<array{index: int, path: string, fileName: string, length: int, kind: string, suggested: bool}>  $files
     * @return array{index: int, path: string, fileName: string, length: int, kind: string, suggested: bool}|null
     */
    public function select(array $files): ?array
    {
        $candidates = array_values(array_filter(
            $files,
            fn (array $file): bool => $file['length'] > 0
                && in_array($this->extension($file['path']), self::SUPPORTED_EXTENSIONS, true),
        ));

        usort(
            $candidates,
            fn (array $left, array $right): int => (
                $this->extensionPriority($left['path'])
                <=> $this->extensionPriority($right['path'])
            )
                ?: ($right['length'] <=> $left['length'])
                ?: ($left['index'] <=> $right['index']),
        );

        return $candidates[0] ?? null;
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function extensionPriority(string $path): int
    {
        return match ($this->extension($path)) {
            'mp4' => 0,
            'mkv' => 1,
            default => PHP_INT_MAX,
        };
    }
}
