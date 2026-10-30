<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TorrentUrl implements ValidationRule
{
    private const PATTERN = '/^(https?:\/\/[^\s]+\/[^\s?]+\.torrent(\?[^\s]*)?|magnet:\?xt=urn:btih:[a-fA-F0-9]{40}(?:&[^\s]*)?|magnet:\?xt=urn:btih:[a-z2-7]{32}(?:&[^\s]*)?)$/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The torrent URL must be a magnet link or an http(s) .torrent link.');
        }
    }
}
