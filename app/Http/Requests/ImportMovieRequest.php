<?php

namespace App\Http\Requests;

use App\Rules\TorrentUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportMovieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'torrent_url' => ['required', 'string', 'max:2048', new TorrentUrl],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
